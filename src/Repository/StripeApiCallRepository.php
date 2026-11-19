<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Lle\SaasBundle\Entity\StripeApiCall;

/**
 * @extends ServiceEntityRepository<StripeApiCall>
 */
class StripeApiCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeApiCall::class);
    }

    /**
     * @return int number of deleted calls
     */
    public function deleteCreatedBefore(DateTimeImmutable $before): int
    {
        $deleted = $this->createQueryBuilder('call')
            ->delete()
            ->andWhere('call.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        return is_int($deleted) ? $deleted : 0;
    }
}
