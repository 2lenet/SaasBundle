<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Service\MonitoringPurger;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;

class MonitoringPurgerTest extends DatabaseTestCase
{
    public const DAYS = 30;

    public function testDeletesTheOldEntriesButKeepsTheFailedEvents(): void
    {
        $this->givenEvent('evt_old', StripeEventStatus::Processed, '-40 days');
        $this->givenEvent('evt_old_failed', StripeEventStatus::Failed, '-40 days');
        $this->givenEvent('evt_recent', StripeEventStatus::Processed, '-1 day');
        $this->givenApiCall('-40 days');
        $this->givenApiCall('-1 day');

        $report = $this->service(MonitoringPurger::class)->purge(self::DAYS, false);

        $this->assertSame(1, $report->getDeletedEvents());
        $this->assertSame(1, $report->getDeletedApiCalls());
        $this->assertSame(['evt_old_failed', 'evt_recent'], $this->remainingEventIds());
    }

    public function testCanDeleteTheFailedEventsToo(): void
    {
        $this->givenEvent('evt_old_failed', StripeEventStatus::Failed, '-40 days');

        $report = $this->service(MonitoringPurger::class)->purge(self::DAYS, true);

        $this->assertSame(1, $report->getDeletedEvents());
        $this->assertSame([], $this->remainingEventIds());
    }

    public function testRetentionMustBeAtLeastOneDay(): void
    {
        $purger = $this->service(MonitoringPurger::class);

        $this->expectException(InvalidArgumentException::class);

        $purger->purge(0, false);
    }

    protected function givenEvent(string $stripeEventId, StripeEventStatus $status, string $receivedAt): void
    {
        $event = new StripeEvent();
        $event->setStripeEventId($stripeEventId)->setType('invoice.created')->setStatus($status);
        $this->persistAndFlush($event);
        $this->entityManager->createQuery('UPDATE ' . StripeEvent::class . ' event SET event.receivedAt = :date WHERE event.id = :id')
            ->setParameter('date', new DateTimeImmutable($receivedAt))
            ->setParameter('id', $event->getId())
            ->execute();
    }

    protected function givenApiCall(string $createdAt): void
    {
        $call = new StripeApiCall();
        $call->setOperation('customers.create')->setSuccess(true);
        $this->persistAndFlush($call);
        $this->entityManager->createQuery('UPDATE ' . StripeApiCall::class . ' call SET call.createdAt = :date WHERE call.id = :id')
            ->setParameter('date', new DateTimeImmutable($createdAt))
            ->setParameter('id', $call->getId())
            ->execute();
    }

    /**
     * @return list<string>
     */
    protected function remainingEventIds(): array
    {
        $ids = array_map(
            static fn (StripeEvent $event): string => (string) $event->getStripeEventId(),
            $this->entityManager->getRepository(StripeEvent::class)->findAll(),
        );
        sort($ids);

        return $ids;
    }
}
