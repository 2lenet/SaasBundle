<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Lle\SaasBundle\Contracts\CustomerInterface;

/**
 * Bound to the configured customer class ("lle_saas.customer_class"): an application replacing the Customer
 * entity can keep this repository with #[ORM\Entity(repositoryClass: CustomerRepository::class)].
 *
 * @extends ServiceEntityRepository<CustomerInterface>
 */
class CustomerRepository extends ServiceEntityRepository
{
    /**
     * @param class-string<CustomerInterface> $customerClass
     */
    public function __construct(ManagerRegistry $registry, string $customerClass)
    {
        parent::__construct($registry, $customerClass);
    }

    public function findOneByStripeCustomerId(string $stripeCustomerId): ?CustomerInterface
    {
        return $this->findOneBy(['stripeCustomerId' => $stripeCustomerId]);
    }

    /**
     * Stripe ids of the local customers, as keys.
     *
     * @return array<string, true>
     */
    public function findStripeCustomerIds(): array
    {
        /** @var list<string> $stripeCustomerIds */
        $stripeCustomerIds = $this->createQueryBuilder('customer')
            ->select('customer.stripeCustomerId')
            ->andWhere('customer.stripeCustomerId IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($stripeCustomerIds, true);
    }
}
