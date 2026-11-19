<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findOneByStripeSubscriptionId(string $stripeSubscriptionId): ?Subscription
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    /**
     * Subscriptions of the customer giving access to the service, with their plan.
     *
     * @return list<Subscription>
     */
    public function findAccessGrantingForCustomer(CustomerInterface $customer): array
    {
        $statuses = array_map(
            static fn (SubscriptionStatus $status): string => $status->value,
            SubscriptionStatus::accessGranting()
        );

        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->createQueryBuilder('subscription')
            ->addSelect('plan')
            ->leftJoin('subscription.plan', 'plan')
            ->andWhere('subscription.customer = :customer')
            ->andWhere('subscription.status IN (:statuses)')
            ->setParameter('customer', $customer)
            ->setParameter('statuses', $statuses)
            ->orderBy('subscription.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $subscriptions;
    }
}
