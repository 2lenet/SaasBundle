<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Webhook;

use ArrayObject;
use DateTimeImmutable;
use Doctrine\ORM\OptimisticLockException;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Exception\InvalidWebhookException;
use Lle\SaasBundle\Repository\StripeEventRepository;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Fixtures\Webhook\RecordingStripeEventHandler;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Lle\SaasBundle\Webhook\WebhookProcessor;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class WebhookProcessorTest extends DatabaseTestCase
{
    public const UNHANDLED_TYPE = 'invoice.created';
    public const EVENT_ID = 'evt_processor';

    public function testUnhandledEventIsJournaledAsIgnored(): void
    {
        $payload = StripeEventPayload::json(self::EVENT_ID, self::UNHANDLED_TYPE, [
            'id' => 'in_1',
            'object' => 'invoice',
            'customer' => StripeEventPayload::STRIPE_CUSTOMER_ID,
        ]);

        $this->receive($payload);

        $journal = $this->journal();
        $this->assertSame(self::UNHANDLED_TYPE, $journal->getType());
        $this->assertSame(StripeEventStatus::Ignored, $journal->getStatus());
        $this->assertFalse($journal->isLivemode());
        $this->assertSame(StripeEventPayload::API_VERSION, $journal->getApiVersion());
        $this->assertSame('invoice', $journal->getObjectType());
        $this->assertSame('in_1', $journal->getObjectId());
        $this->assertSame(StripeEventPayload::STRIPE_CUSTOMER_ID, $journal->getStripeCustomerId());
        $this->assertSame(self::EVENT_ID, $journal->getPayload()['id']);
        $this->assertSame(StripeEventPayload::CREATED, $journal->getStripeCreatedAt()?->getTimestamp());
        $this->assertSame(1, $journal->getAttempts());
        $this->assertNotNull($journal->getProcessedAt());
        $this->assertNotNull($journal->getDurationMs());
    }

    public function testCustomerEventIsJournaledWithItsStripeCustomer(): void
    {
        $this->receive(StripeEventPayload::json(self::EVENT_ID, 'customer.created', [
            'id' => StripeEventPayload::STRIPE_CUSTOMER_ID,
            'object' => 'customer',
        ]));

        $this->assertSame(StripeEventPayload::STRIPE_CUSTOMER_ID, $this->journal()->getStripeCustomerId());
    }

    public function testHandledEventIsProcessedOnlyOnce(): void
    {
        $payload = $this->recordedEvent();

        $this->receive($payload);
        $duplicate = $this->receive($payload);

        $this->assertSame(StripeEventStatus::Processed, $duplicate->getStatus());
        $this->assertSame(1, $this->handler()->calls);
        $this->assertCount(1, $this->service(StripeEventRepository::class)->findAll());
        $this->assertSame(StripeEventStatus::Processed, $this->journal()->getStatus());
        $this->assertNotNull($this->entityManager->getRepository(Plan::class)->findOneBy(['code' => RecordingStripeEventHandler::PLAN_CODE]));
    }

    public function testFailingHandlerMarksTheEventFailedAndRollsBackItsWork(): void
    {
        $this->handler()->failing = true;

        $result = $this->receive($this->recordedEvent());

        $this->assertSame(StripeEventStatus::Failed, $result->getStatus());
        $journal = $this->journal();
        $this->assertSame(StripeEventStatus::Failed, $journal->getStatus());
        $this->assertSame(1, $journal->getAttempts());
        $this->assertStringContainsString(RecordingStripeEventHandler::FAILURE, (string) $journal->getError());
        $this->assertSame([], $this->entityManager->getRepository(Plan::class)->findAll());
    }

    public function testFailedEventIsProcessedWhenStripeSendsItAgain(): void
    {
        $payload = $this->recordedEvent();
        $this->handler()->failing = true;
        $this->receive($payload);
        $this->handler()->failing = false;

        $result = $this->receive($payload);

        $this->assertSame(StripeEventStatus::Processed, $result->getStatus());
        $journal = $this->journal();
        $this->assertSame(StripeEventStatus::Processed, $journal->getStatus());
        $this->assertSame(2, $journal->getAttempts());
        $this->assertNull($journal->getError());
    }

    public function testReplayProcessesTheStoredPayload(): void
    {
        $this->handler()->failing = true;
        $this->receive($this->recordedEvent());
        $this->handler()->failing = false;
        $failed = $this->journal();

        $result = $this->processor()->replay($failed);

        $this->assertSame(StripeEventStatus::Processed, $result->getStatus());
        $this->assertSame(2, $this->handler()->calls);
        $this->assertSame(StripeEventStatus::Processed, $this->journal()->getStatus());
    }

    public function testInvalidSignatureIsRejectedWithoutJournal(): void
    {
        $processor = $this->processor();
        $payload = $this->recordedEvent();
        $signature = StripeEventPayload::sign($payload, 'whsec_other');

        $caught = null;
        try {
            $processor->receive($payload, $signature);
        } catch (InvalidWebhookException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame([], $this->service(StripeEventRepository::class)->findAll());
    }

    public function testConcurrentDeliveryIsNotProcessedTwice(): void
    {
        $payload = $this->recordedEvent();
        $this->handler()->failing = true;
        $this->receive($payload);
        $this->setClaim(StripeEventStatus::Processing, new DateTimeImmutable());
        $this->handler()->failing = false;

        $result = $this->receive($payload);

        $this->assertSame(StripeEventStatus::Processing, $result->getStatus());
        $this->assertSame(1, $this->handler()->calls);
    }

    public function testAbandonedProcessingIsClaimedAgain(): void
    {
        $payload = $this->recordedEvent();
        $this->handler()->failing = true;
        $this->receive($payload);
        $this->setClaim(StripeEventStatus::Processing, new DateTimeImmutable('-1 hour'));
        $this->handler()->failing = false;

        $result = $this->receive($payload);

        $this->assertSame(StripeEventStatus::Processed, $result->getStatus());
        $this->assertSame(2, $this->journal()->getAttempts());
    }

    public function testObjectOfAnotherApplicationIsIgnored(): void
    {
        $this->handler()->exceptions = [new ForeignStripeObjectException('sub_other')];

        $result = $this->receive($this->recordedEvent());

        $this->assertSame(StripeEventStatus::Ignored, $result->getStatus());
        $this->assertSame([], $this->entityManager->getRepository(Plan::class)->findAll());
    }

    public function testConflictWithAConcurrentDeliveryIsRunOnceMore(): void
    {
        $this->handler()->exceptions = [new OptimisticLockException('Subscription changed meanwhile', null)];

        $result = $this->receive($this->recordedEvent());

        $this->assertSame(StripeEventStatus::Processed, $result->getStatus());
        $this->assertSame(2, $this->handler()->calls);
        $this->assertCount(1, $this->entityManager->getRepository(Plan::class)->findAll());
    }

    public function testRepeatedConflictFails(): void
    {
        $this->handler()->exceptions = [
            new OptimisticLockException('Subscription changed meanwhile', null),
            new OptimisticLockException('Subscription changed meanwhile', null),
        ];

        $result = $this->receive($this->recordedEvent());

        $this->assertSame(StripeEventStatus::Failed, $result->getStatus());
        $this->assertSame(WebhookProcessor::MAX_RUNS, $this->handler()->calls);
    }

    public function testEventsOfTheBundleAreDispatchedAfterTheCommit(): void
    {
        $connection = $this->entityManager->getConnection();
        $transactionActive = null;
        $this->dispatcher()->addListener(ArrayObject::class, static function () use ($connection, &$transactionActive): void {
            $transactionActive = $connection->isTransactionActive();
        });
        $this->handler()->lifecycleEvent = new ArrayObject();

        $this->receive($this->recordedEvent());

        $this->assertFalse($transactionActive);
    }

    public function testEventsOfTheBundleAreDroppedWhenTheTransactionIsRolledBack(): void
    {
        $dispatched = false;
        $this->dispatcher()->addListener(ArrayObject::class, static function () use (&$dispatched): void {
            $dispatched = true;
        });
        $this->handler()->lifecycleEvent = new ArrayObject();
        $this->handler()->failing = true;

        $this->receive($this->recordedEvent());

        $this->assertFalse($dispatched);
    }

    public function testFailingListenerIsNotedButTheEventStaysProcessed(): void
    {
        $this->dispatcher()->addListener(ArrayObject::class, static function (): void {
            throw new RuntimeException('Mailer down');
        });
        $this->handler()->lifecycleEvent = new ArrayObject();

        $this->receive($this->recordedEvent());

        $journal = $this->journal();
        $this->assertSame(StripeEventStatus::Processed, $journal->getStatus());
        $this->assertStringStartsWith(WebhookProcessor::LISTENER_FAILURE, (string) $journal->getError());
        $this->assertStringContainsString('Mailer down', (string) $journal->getError());
        $this->assertCount(1, $this->entityManager->getRepository(Plan::class)->findAll());
    }

    protected function setClaim(StripeEventStatus $status, DateTimeImmutable $claimedAt): void
    {
        $this->entityManager->createQuery(
            'UPDATE ' . StripeEvent::class . ' event SET event.status = :status, event.claimedAt = :claimedAt WHERE event.stripeEventId = :id'
        )
            ->setParameter('status', $status->value)
            ->setParameter('claimedAt', $claimedAt)
            ->setParameter('id', self::EVENT_ID)
            ->execute();
    }

    protected function dispatcher(): EventDispatcherInterface
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');

        return $dispatcher;
    }

    protected function receive(string $payload): StripeEvent
    {
        return $this->processor()->receive($payload, StripeEventPayload::sign($payload, TestKernel::WEBHOOK_SECRET));
    }

    protected function recordedEvent(): string
    {
        return StripeEventPayload::json(self::EVENT_ID, RecordingStripeEventHandler::TYPE, ['id' => 'obj_1', 'object' => 'test']);
    }

    protected function processor(): WebhookProcessor
    {
        return $this->service(WebhookProcessor::class);
    }

    protected function handler(): RecordingStripeEventHandler
    {
        return $this->service(RecordingStripeEventHandler::class);
    }

    protected function journal(): StripeEvent
    {
        $this->entityManager->clear();
        $journal = $this->service(StripeEventRepository::class)->findOneByStripeEventId(self::EVENT_ID);
        $this->assertNotNull($journal);

        return $journal;
    }
}
