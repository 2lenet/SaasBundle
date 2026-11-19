<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Lle\SaasBundle\Doctrine\EntityManagerResetter;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Exception\InvalidWebhookException;
use Lle\SaasBundle\Monitoring\Duration;
use Lle\SaasBundle\Repository\StripeEventRepository;
use Lle\SaasBundle\Service\LifecycleEventDispatcher;
use Lle\SaasBundle\Stripe\StripeApiCallJournalInterface;
use Lle\SaasBundle\Stripe\StripeApiCallRecorder;
use Lle\SaasBundle\Stripe\StripeObjects;
use Lle\SaasBundle\Stripe\StripeTime;
use Lle\SaasBundle\Stripe\WebhookEventFactory;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\StripeObject;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Receives the Stripe webhook events:
 *  1. the signature is checked, then every event is journaled in StripeEvent (handled or not);
 *  2. an event already processed (or ignored) is not processed again: Stripe may deliver it twice;
 *  3. the event is claimed ("processing") with a conditional update: a concurrent delivery of the same event gets
 *     a 409 and is sent again later by Stripe;
 *  4. the handlers supporting its type run in one transaction; no handler, or a Stripe object of another application
 *     of the account → "ignored"; a conflict with a concurrent delivery (same subscription) → run once more;
 *  5. the events of the bundle are dispatched to the application once the transaction is committed;
 *  6. the outcome (status, attempts, error, duration) is saved, even when the transaction was rolled back.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
#[WithMonologChannel(StripeApiCallRecorder::LOG_CHANNEL)]
class WebhookProcessor
{
    /** A "processing" event claimed before is considered abandoned (crash, timeout) and can be claimed again. */
    public const CLAIM_TIMEOUT = '-10 minutes';
    /** Runs of the handlers when they conflict with a concurrent delivery (unique constraint, optimistic lock). */
    public const MAX_RUNS = 2;
    public const LISTENER_FAILURE = 'Listener failure, changes committed: ';
    public const ERROR_SEPARATOR = ' | ';

    /** Statuses a Stripe delivery may claim (processed / ignored ones are duplicates). */
    public const RECEIVE_CLAIMABLE = [StripeEventStatus::Received, StripeEventStatus::Failed];
    /** Statuses a replay may claim: any, unless being processed. */
    public const REPLAY_CLAIMABLE = [
        StripeEventStatus::Received,
        StripeEventStatus::Failed,
        StripeEventStatus::Processed,
        StripeEventStatus::Ignored,
    ];

    /**
     * @param iterable<StripeEventHandlerInterface> $handlers
     */
    public function __construct(
        protected WebhookEventFactory $eventFactory,
        protected StripeEventRepository $stripeEventRepository,
        protected EntityManagerResetter $entityManagers,
        #[AutowireIterator(StripeEventHandlerInterface::TAG)]
        protected iterable $handlers,
        protected LifecycleEventDispatcher $lifecycleEvents,
        protected StripeApiCallJournalInterface $apiCallJournal,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @throws InvalidWebhookException
     */
    public function receive(string $payload, string $signature): StripeEvent
    {
        try {
            $event = $this->eventFactory->create($payload, $signature);
        } catch (InvalidWebhookException $exception) {
            $this->logger->warning($exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }

        $journal = $this->stripeEventRepository->findOneByStripeEventId($event->id);
        if ($journal !== null && $this->isDone($journal)) {
            $this->logger->info('Stripe event ' . $event->id . ' already ' . $journal->getStatus()->value, $this->context($journal));

            return $journal;
        }

        if ($journal === null) {
            $journal = $this->journal($event);
            $entityManager = $this->entityManager();
            $entityManager->persist($journal);
            $entityManager->flush();
        }

        return $this->process($journal, self::RECEIVE_CLAIMABLE);
    }

    /**
     * Processes again the stored payload of an event, whatever its status (unless it is being processed).
     */
    public function replay(StripeEvent $journal): StripeEvent
    {
        return $this->process($journal, self::REPLAY_CLAIMABLE);
    }

    /**
     * @param list<StripeEventStatus> $claimable
     */
    protected function process(StripeEvent $journal, array $claimable): StripeEvent
    {
        $claimed = $this->claim($journal, $claimable);
        $journal = $this->managed($journal);
        if (!$claimed) {
            $this->logger->info('Stripe event ' . $journal->getStripeEventId() . ' is being processed by another delivery', $this->context($journal));

            return $journal;
        }

        $start = Duration::start();
        $handlers = $this->handlersFor((string) $journal->getType());
        $journal->setError(null);

        if ($handlers === []) {
            $journal->setStatus(StripeEventStatus::Ignored);
        } else {
            $this->run($journal, Event::constructFrom($journal->getPayload()), $handlers);
        }
        $this->apiCallJournal->release();

        $journal
            ->setProcessedAt(new DateTimeImmutable())
            ->setDurationMs(Duration::millisecondsSince($start));
        $journal = $this->saveOutcome($journal);

        if ($journal->getStatus() !== StripeEventStatus::Failed) {
            $this->logger->info('Stripe event ' . $journal->getStripeEventId() . ' ' . $journal->getStatus()->value, $this->context($journal));
        }

        return $journal;
    }

    /**
     * @param list<StripeEventHandlerInterface> $handlers
     */
    protected function run(StripeEvent $journal, Event $event, array $handlers): void
    {
        $run = 1;
        while (true) {
            $this->lifecycleEvents->defer();
            try {
                $this->entityManager()->wrapInTransaction(static function () use ($handlers, $event): void {
                    foreach ($handlers as $handler) {
                        $handler->handle($event);
                    }
                });
            } catch (ForeignStripeObjectException $exception) {
                $this->lifecycleEvents->discard();
                $journal->setStatus(StripeEventStatus::Ignored);
                $this->logger->info($exception->getMessage(), $this->context($journal));

                return;
            } catch (UniqueConstraintViolationException | OptimisticLockException $exception) {
                $this->lifecycleEvents->discard();
                if ($run < self::MAX_RUNS) {
                    $this->logger->info('Stripe event ' . $journal->getStripeEventId() . ' conflicts with a concurrent delivery, run again', $this->context($journal));
                    ++$run;
                    continue;
                }
                $this->fail($journal, $exception);

                return;
            } catch (Throwable $exception) {
                $this->lifecycleEvents->discard();
                $this->fail($journal, $exception);

                return;
            }

            $journal->setStatus(StripeEventStatus::Processed);
            $this->dispatchLifecycleEvents($journal);

            return;
        }
    }

    /**
     * After the commit: a failing listener is logged and noted on the event, which stays processed.
     */
    protected function dispatchLifecycleEvents(StripeEvent $journal): void
    {
        $messages = [];
        foreach ($this->lifecycleEvents->release() as $failure) {
            $messages[] = $failure::class . ': ' . $failure->getMessage();
            $this->logger->error(
                'Stripe event ' . $journal->getStripeEventId() . ': listener failure: ' . $failure->getMessage(),
                $this->context($journal) + ['exception' => $failure],
            );
        }
        if ($messages !== []) {
            $journal->setError(self::LISTENER_FAILURE . implode(self::ERROR_SEPARATOR, $messages));
        }
    }

    protected function fail(StripeEvent $journal, Throwable $exception): void
    {
        $journal->setStatus(StripeEventStatus::Failed)->setError($exception::class . ': ' . $exception->getMessage());
        $this->logger->error(
            'Stripe event ' . $journal->getStripeEventId() . ' failed: ' . $exception->getMessage(),
            $this->context($journal) + ['exception' => $exception],
        );
    }

    /**
     * Sets the event "processing" if its status allows it (or if a previous processing was abandoned), in one
     * conditional update: of two concurrent deliveries, only one claims it.
     *
     * @param list<StripeEventStatus> $claimable
     */
    protected function claim(StripeEvent $journal, array $claimable): bool
    {
        $entityManager = $this->entityManager();
        $metadata = $entityManager->getClassMetadata(StripeEvent::class);
        $status = $metadata->getColumnName('status');
        $claimedAt = $metadata->getColumnName('claimedAt');
        $attempts = $metadata->getColumnName('attempts');

        $sql = sprintf(
            'UPDATE %1$s SET %2$s = :processing, %3$s = :now, %4$s = %4$s + 1 WHERE %5$s = :id'
            . ' AND (%2$s IN (:claimable) OR (%2$s = :processing AND %3$s < :abandoned))',
            $metadata->getTableName(),
            $status,
            $claimedAt,
            $attempts,
            $metadata->getSingleIdentifierColumnName(),
        );

        $claimed = $entityManager->getConnection()->executeStatement(
            $sql,
            [
                'processing' => StripeEventStatus::Processing->value,
                'now' => new DateTimeImmutable(),
                'id' => $journal->getId(),
                'claimable' => array_map(static fn (StripeEventStatus $claimableStatus): string => $claimableStatus->value, $claimable),
                'abandoned' => new DateTimeImmutable(self::CLAIM_TIMEOUT),
            ],
            [
                'now' => Types::DATETIME_IMMUTABLE,
                'claimable' => ArrayParameterType::STRING,
                'abandoned' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return $claimed === 1;
    }

    /**
     * The journal as currently stored, managed by the (possibly reset) entity manager.
     */
    protected function managed(StripeEvent $journal): StripeEvent
    {
        $entityManager = $this->entityManager();
        if ($entityManager->contains($journal)) {
            $entityManager->refresh($journal);

            return $journal;
        }

        return $entityManager->find(StripeEvent::class, $journal->getId()) ?? $journal;
    }

    /**
     * A failed transaction closes the entity manager: it is reset, and the outcome written on a fresh copy.
     */
    protected function saveOutcome(StripeEvent $journal): StripeEvent
    {
        $entityManager = $this->entityManager();
        if (!$entityManager->contains($journal)) {
            $managed = $entityManager->find(StripeEvent::class, $journal->getId());
            if ($managed === null) {
                return $journal;
            }
            $managed
                ->setStatus($journal->getStatus())
                ->setError($journal->getError())
                ->setProcessedAt($journal->getProcessedAt())
                ->setDurationMs($journal->getDurationMs());
            $journal = $managed;
        }
        $entityManager->flush();

        return $journal;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return $this->entityManagers->openManagerFor(StripeEvent::class);
    }

    /**
     * @return list<StripeEventHandlerInterface>
     */
    protected function handlersFor(string $eventType): array
    {
        $handlers = [];
        foreach ($this->handlers as $handler) {
            if ($handler->supports($eventType)) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }

    protected function isDone(StripeEvent $journal): bool
    {
        return in_array($journal->getStatus(), [StripeEventStatus::Processed, StripeEventStatus::Ignored], true);
    }

    protected function journal(Event $event): StripeEvent
    {
        $object = $event->data->object;
        $objectId = $object->offsetExists('id') ? $object['id'] : null;
        $objectType = $object->offsetExists('object') ? $object['object'] : null;

        $journal = new StripeEvent();
        $journal
            ->setStripeEventId($event->id)
            ->setType($event->type)
            ->setLivemode($event->livemode)
            ->setApiVersion($event->api_version)
            ->setObjectType(is_string($objectType) ? $objectType : null)
            ->setObjectId(is_string($objectId) ? $objectId : null)
            ->setStripeCustomerId($this->stripeCustomerId($object, $objectType, $objectId))
            ->setPayload($event->toArray())
            ->setStripeCreatedAt(StripeTime::toDateTime($event->created));

        return $journal;
    }

    protected function stripeCustomerId(StripeObject $object, mixed $objectType, mixed $objectId): ?string
    {
        if ($objectType === StripeObjects::CUSTOMER_OBJECT) {
            return is_string($objectId) ? $objectId : null;
        }

        $customer = $object->offsetExists(StripeObjects::CUSTOMER_FIELD) ? $object[StripeObjects::CUSTOMER_FIELD] : null;
        if ($customer instanceof StripeObject) {
            $customer = $customer->id;
        }

        return is_string($customer) ? $customer : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(StripeEvent $journal): array
    {
        return [
            'stripe_event_id' => $journal->getStripeEventId(),
            'type' => $journal->getType(),
            'status' => $journal->getStatus()->value,
            'attempts' => $journal->getAttempts(),
            'duration_ms' => $journal->getDurationMs(),
            'stripe_customer_id' => $journal->getStripeCustomerId(),
        ];
    }
}
