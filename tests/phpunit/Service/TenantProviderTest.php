<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Service\TenantProvider;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

class TenantProviderTest extends TestCase
{
    public const FIREWALL = 'main';
    public const CUSTOMER_ID = 12;

    public function testAnonymousHasNoTenant(): void
    {
        $provider = new TenantProvider(new TokenStorage());

        $this->assertNull($provider->getCurrentUser());
        $this->assertNull($provider->getCurrentCustomer());
        $this->assertNull($provider->getTenantId());
    }

    public function testUserOutsideTheSaasContractHasNoTenant(): void
    {
        $provider = $this->provider(new InMemoryUser('admin', null));

        $this->assertNull($provider->getCurrentUser());
        $this->assertNull($provider->getTenantId());
    }

    public function testUserWithoutCustomerHasNoTenant(): void
    {
        $user = new User(CustomerTest::USER_EMAIL);
        $provider = $this->provider($user);

        $this->assertSame($user, $provider->getCurrentUser());
        $this->assertNull($provider->getCurrentCustomer());
        $this->assertNull($provider->getTenantId());
    }

    public function testTenantIsTheCustomerOfTheConnectedUser(): void
    {
        $customer = new Customer();
        (new ReflectionProperty(Customer::class, 'id'))->setValue($customer, self::CUSTOMER_ID);
        $user = new User(CustomerTest::USER_EMAIL);
        $customer->addUser($user);
        $provider = $this->provider($user);

        $this->assertSame($customer, $provider->getCurrentCustomer());
        $this->assertSame(self::CUSTOMER_ID, $provider->getTenantId());
    }

    protected function provider(UserInterface $user): TenantProvider
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles()));

        return new TenantProvider($tokenStorage);
    }
}
