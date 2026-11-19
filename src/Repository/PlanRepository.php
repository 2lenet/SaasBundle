<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Lle\SaasBundle\Entity\Plan;

/**
 * @extends ServiceEntityRepository<Plan>
 */
class PlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Plan::class);
    }

    public function findOneByStripePriceId(string $stripePriceId): ?Plan
    {
        return $this->findOneBy(['stripePriceId' => $stripePriceId]);
    }

    public function findOneByCode(string $code): ?Plan
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Plans currently on sale, in display order.
     *
     * @return list<Plan>
     */
    public function findActive(): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->createQueryBuilder('plan')
            ->andWhere('plan.active = :active')
            ->setParameter('active', true)
            ->orderBy('plan.position', 'ASC')
            ->addOrderBy('plan.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $plans;
    }
}
