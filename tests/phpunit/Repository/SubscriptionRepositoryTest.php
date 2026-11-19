<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Repository;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Tests\Entity\CustomerTest;

class SubscriptionRepositoryTest extends DatabaseTestCase
{
    public function testFindOneByStripeSubscriptionId(): void
    {
        $customer = $this->customer();
        $this->persistAndFlush($customer, $this->subscription($customer, 'sub_1', SubscriptionStatus::Active));
        $repository = $this->service(SubscriptionRepository::class);

        $subscription = $repository->findOneByStripeSubscriptionId('sub_1');

        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        $this->assertNull($repository->findOneByStripeSubscriptionId('sub_unknown'));
    }

    public function testFindAccessGrantingForCustomerKeepsOnlyItsOpenSubscriptions(): void
    {
        $customer = $this->customer();
        $otherCustomer = $this->customer();
        $this->persistAndFlush(
            $customer,
            $otherCustomer,
            $this->subscription($customer, 'sub_active', SubscriptionStatus::Active),
            $this->subscription($customer, 'sub_past_due', SubscriptionStatus::PastDue),
            $this->subscription($customer, 'sub_canceled', SubscriptionStatus::Canceled),
            $this->subscription($otherCustomer, 'sub_other', SubscriptionStatus::Active),
        );
        $repository = $this->service(SubscriptionRepository::class);
        /** @var Customer $reloaded */
        $reloaded = $this->entityManager->find(Customer::class, $customer->getId());

        $ids = array_map(
            static fn (Subscription $subscription): ?string => $subscription->getStripeSubscriptionId(),
            $repository->findAccessGrantingForCustomer($reloaded)
        );
        sort($ids);

        $this->assertSame(['sub_active', 'sub_past_due'], $ids);
    }

    protected function customer(): Customer
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME);

        return $customer;
    }

    protected function subscription(Customer $customer, string $stripeId, SubscriptionStatus $status): Subscription
    {
        $subscription = new Subscription();
        $subscription->setCustomer($customer)->setStripeSubscriptionId($stripeId)->setStatus($status);

        return $subscription;
    }
}
