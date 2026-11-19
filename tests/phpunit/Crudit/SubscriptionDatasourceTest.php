<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Crudit;

use Lle\CruditBundle\Filter\FilterState;
use Lle\SaasBundle\Crudit\Datasource\SubscriptionDatasource;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;

class SubscriptionDatasourceTest extends DatabaseTestCase
{
    public function testListLoadsTheCustomerAndThePlanWithTheSubscriptions(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME);
        $plan = new Plan();
        $plan->setCode('pro')->setLabel('Pro')->setStripeProductId('prod_pro')->setStripePriceId('price_pro');
        $subscription = new Subscription();
        $subscription->setCustomer($customer)->setPlan($plan)->setStripeSubscriptionId('sub_1');
        $this->persistAndFlush($customer, $plan, $subscription);
        $datasource = new SubscriptionDatasource($this->entityManager, $this->createStub(FilterState::class));

        $subscriptions = [...$datasource->list(null)];

        $this->assertCount(1, $subscriptions);
        $listed = $subscriptions[0];
        $this->assertInstanceOf(Subscription::class, $listed);
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $this->assertFalse($unitOfWork->isUninitializedObject($listed->getCustomer()));
        $this->assertFalse($unitOfWork->isUninitializedObject($listed->getPlan()));
    }
}
