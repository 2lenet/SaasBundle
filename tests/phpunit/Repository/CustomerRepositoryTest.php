<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Repository;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Repository\CustomerRepository;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;

class CustomerRepositoryTest extends DatabaseTestCase
{
    public const STRIPE_CUSTOMER_ID = 'cus_123';

    public function testFindOneByStripeCustomerId(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId(self::STRIPE_CUSTOMER_ID);
        $this->persistAndFlush($customer);
        $repository = $this->service(CustomerRepository::class);

        $found = $repository->findOneByStripeCustomerId(self::STRIPE_CUSTOMER_ID);

        $this->assertNotNull($found);
        $this->assertSame(CustomerTest::CUSTOMER_NAME, $found->getName());
        $this->assertNull($repository->findOneByStripeCustomerId('cus_unknown'));
    }

    public function testUsersArePersistedThroughTheOwningSide(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME);
        $user = new User(CustomerTest::USER_EMAIL);
        $user->setCustomerRole(CustomerRole::Owner);
        $customer->addUser($user);
        $this->persistAndFlush($customer, $user);
        $repository = $this->service(CustomerRepository::class);

        $found = $repository->find($customer->getId());

        $this->assertInstanceOf(Customer::class, $found);
        $this->assertCount(1, $found->getUsers());
        $owner = $found->getUsers()->first();
        $this->assertInstanceOf(User::class, $owner);
        $this->assertSame(CustomerTest::USER_EMAIL, $owner->getEmail());
        $this->assertTrue($owner->isCustomerOwner());
    }
}
