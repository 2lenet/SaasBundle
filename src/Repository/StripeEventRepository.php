<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;

/**
 * @extends ServiceEntityRepository<StripeEvent>
 */
class StripeEventRepository extends ServiceEntityRepository
{
    public const DEFAULT_LIMIT = 100;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeEvent::class);
    }

    public function findOneByStripeEventId(string $stripeEventId): ?StripeEvent
    {
        return $this->findOneBy(['stripeEventId' => $stripeEventId]);
    }

    /**
     * Failed events, oldest first (replay order).
     *
     * @return list<StripeEvent>
     */
    public function findFailed(int $limit = self::DEFAULT_LIMIT): array
    {
        /** @var list<StripeEvent> $events */
        $events = $this->createQueryBuilder('event')
            ->andWhere('event.status = :status')
            ->setParameter('status', StripeEventStatus::Failed->value)
            ->orderBy('event.receivedAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * Deletes the events received before the date. Failed events are kept unless $includeFailed: they still have to be
     * looked at (or replayed).
     *
     * @return int number of deleted events
     */
    public function deleteReceivedBefore(DateTimeImmutable $before, bool $includeFailed): int
    {
        $queryBuilder = $this->createQueryBuilder('event')
            ->delete()
            ->andWhere('event.receivedAt < :before')
            ->setParameter('before', $before);
        if (!$includeFailed) {
            $queryBuilder->andWhere('event.status <> :failed')->setParameter('failed', StripeEventStatus::Failed->value);
        }
        $deleted = $queryBuilder->getQuery()->execute();

        return is_int($deleted) ? $deleted : 0;
    }
}
