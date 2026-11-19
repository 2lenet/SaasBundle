<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Command;

use Lle\SaasBundle\Command\WebhookReplayCommand;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Repository\StripeEventRepository;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Fixtures\Webhook\RecordingStripeEventHandler;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class WebhookReplayCommandTest extends DatabaseTestCase
{
    public const FAILED_EVENT_ID = 'evt_failed';
    public const PROCESSED_EVENT_ID = 'evt_processed';

    public function testReplaysTheFailedEvents(): void
    {
        $this->givenEvent(self::FAILED_EVENT_ID, StripeEventStatus::Failed);
        $this->givenEvent(self::PROCESSED_EVENT_ID, StripeEventStatus::Processed);
        $tester = $this->tester();

        $exitCode = $tester->execute(['--' . WebhookReplayCommand::OPTION_FAILED => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(1, $this->handler()->calls);
        $this->assertSame(StripeEventStatus::Processed, $this->eventStatus(self::FAILED_EVENT_ID));
        $this->assertStringContainsString(self::FAILED_EVENT_ID, $tester->getDisplay());
    }

    public function testReplaysOneEventWhateverItsStatus(): void
    {
        $this->givenEvent(self::PROCESSED_EVENT_ID, StripeEventStatus::Processed);
        $tester = $this->tester();

        $exitCode = $tester->execute([WebhookReplayCommand::ARGUMENT_EVENT_ID => self::PROCESSED_EVENT_ID]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(1, $this->handler()->calls);
    }

    public function testReportsEventsStillFailing(): void
    {
        $this->givenEvent(self::FAILED_EVENT_ID, StripeEventStatus::Failed);
        $this->handler()->failing = true;
        $tester = $this->tester();

        $exitCode = $tester->execute(['--' . WebhookReplayCommand::OPTION_FAILED => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(RecordingStripeEventHandler::FAILURE, $tester->getDisplay());
    }

    public function testUnknownEventFails(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([WebhookReplayCommand::ARGUMENT_EVENT_ID => 'evt_unknown']);

        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testAnEventIdOrTheFailedOptionIsRequired(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::INVALID, $exitCode);
    }

    protected function givenEvent(string $stripeEventId, StripeEventStatus $status): void
    {
        $event = new StripeEvent();
        $event->setStripeEventId($stripeEventId)
            ->setType(RecordingStripeEventHandler::TYPE)
            ->setStatus($status)
            ->setAttempts(1)
            ->setPayload(StripeEventPayload::event($stripeEventId, RecordingStripeEventHandler::TYPE, ['id' => 'obj_1', 'object' => 'test']));
        $this->persistAndFlush($event);
    }

    protected function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? static::bootKernel());

        return new CommandTester($application->find(WebhookReplayCommand::NAME));
    }

    protected function handler(): RecordingStripeEventHandler
    {
        return $this->service(RecordingStripeEventHandler::class);
    }

    protected function eventStatus(string $stripeEventId): ?StripeEventStatus
    {
        $this->entityManager->clear();

        return $this->service(StripeEventRepository::class)->findOneByStripeEventId($stripeEventId)?->getStatus();
    }
}
