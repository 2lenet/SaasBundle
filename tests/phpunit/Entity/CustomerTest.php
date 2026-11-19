<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Entity;

use DateTimeImmutable;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class CustomerTest extends TestCase
{
    public const CUSTOMER_NAME = 'ACME';
    public const USER_EMAIL = 'jane@acme.test';

    public function testNewCustomerHasEmptyCollectionsAndCreationDate(): void
    {
        $before = new DateTimeImmutable();

        $customer = new Customer();

        $this->assertCount(0, $customer->getUsers());
        $this->assertCount(0, $customer->getSubscriptions());
        $this->assertGreaterThanOrEqual($before, $customer->getCreatedAt());
    }

    public function testToStringReturnsName(): void
    {
        $customer = new Customer();
        $customer->setName(self::CUSTOMER_NAME);

        $this->assertSame(self::CUSTOMER_NAME, (string) $customer);
    }

    public function testAddUserSetsOwningSide(): void
    {
        $customer = new Customer();
        $user = new User(self::USER_EMAIL);

        $customer->addUser($user);
        $customer->addUser($user);

        $this->assertCount(1, $customer->getUsers());
        $this->assertSame($customer, $user->getCustomer());
    }

    public function testRemoveUserClearsOwningSide(): void
    {
        $customer = new Customer();
        $user = new User(self::USER_EMAIL);
        $customer->addUser($user);

        $customer->removeUser($user);

        $this->assertCount(0, $customer->getUsers());
        $this->assertNull($user->getCustomer());
    }

    public function testTenantIdIsTheCustomerId(): void
    {
        $customer = new Customer();
        $this->assertNull($customer->getTenantId());

        $this->forceId($customer, 42);

        $this->assertSame(42, $customer->getTenantId());
    }

    public function testUserTenantIdComesFromItsCustomer(): void
    {
        $customer = new Customer();
        $this->forceId($customer, 7);
        $user = new User(self::USER_EMAIL);

        $this->assertNull($user->getTenantId());

        $customer->addUser($user);

        $this->assertSame(7, $user->getTenantId());
    }

    public function testUserIsOwnerOnlyWithOwnerRole(): void
    {
        $user = new User(self::USER_EMAIL);
        $this->assertFalse($user->isCustomerOwner());

        $user->setCustomerRole(CustomerRole::Member);
        $this->assertFalse($user->isCustomerOwner());

        $user->setCustomerRole(CustomerRole::Owner);
        $this->assertTrue($user->isCustomerOwner());
    }

    protected function forceId(Customer $customer, int $id): void
    {
        $property = new ReflectionProperty(Customer::class, 'id');
        $property->setValue($customer, $id);
    }
}
