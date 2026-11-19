<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Service\SubscriptionChecker;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class SubscriptionCheckerTest extends DatabaseTestCase
{
    public const PRO = 'pro';
    public const STARTER = 'starter';

    public function testActiveSubscriptionAndPlan(): void
    {
        $customer = $this->givenCustomer();
        $this->givenSubscription($customer, self::STARTER, SubscriptionStatus::Canceled, 'sub_old');
        $this->givenSubscription($customer, self::PRO, SubscriptionStatus::PastDue, 'sub_current');
        $checker = $this->service(SubscriptionChecker::class);

        $this->assertTrue($checker->hasActiveSubscription($customer));
        $this->assertTrue($checker->hasPlan(self::PRO, $customer));
        $this->assertFalse($checker->hasPlan(self::STARTER, $customer));
        $this->assertSame('sub_current', $checker->getActiveSubscription($customer)?->getStripeSubscriptionId());
    }

    public function testCustomerWithoutSubscription(): void
    {
        $customer = $this->givenCustomer();
        $checker = $this->service(SubscriptionChecker::class);

        $this->assertFalse($checker->hasActiveSubscription($customer));
        $this->assertNull($checker->getActiveSubscription($customer));
    }

    public function testDefaultsToTheCustomerOfTheConnectedUser(): void
    {
        $customer = $this->givenCustomer();
        $this->givenSubscription($customer, self::PRO, SubscriptionStatus::Active, 'sub_current');
        $user = new User(CustomerTest::USER_EMAIL);
        $user->setCustomerRole(CustomerRole::Member);
        $customer->addUser($user);
        $this->service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main'));
        $checker = $this->service(SubscriptionChecker::class);

        $this->assertTrue($checker->hasPlan(self::PRO));
    }

    public function testAnonymousHasNoSubscription(): void
    {
        $checker = $this->service(SubscriptionChecker::class);

        $this->assertFalse($checker->hasActiveSubscription());
    }

    public function testResultIsKeptUntilReset(): void
    {
        $customer = $this->givenCustomer();
        $checker = $this->service(SubscriptionChecker::class);
        $this->assertFalse($checker->hasActiveSubscription($customer));
        $this->givenSubscription($customer, self::PRO, SubscriptionStatus::Active, 'sub_new');

        $beforeReset = $checker->hasActiveSubscription($customer);
        $checker->reset();

        $this->assertFalse($beforeReset);
        $this->assertTrue($checker->hasActiveSubscription($customer));
    }

    public function testCacheEntryIsNeverGivenToAnotherCustomer(): void
    {
        $subscribed = $this->givenCustomer();
        $this->givenSubscription($subscribed, self::PRO, SubscriptionStatus::Active, 'sub_current');
        $notSubscribedId = $this->givenCustomer()->getId();
        $checker = $this->service(SubscriptionChecker::class);
        $this->assertTrue($checker->hasActiveSubscription($subscribed));
        // a batch clears the entity manager between customers: the freed objects may share their memory slot
        unset($subscribed);
        $this->entityManager->clear();

        $notSubscribed = $this->entityManager->find(Customer::class, $notSubscribedId);

        $this->assertNotNull($notSubscribed);
        $this->assertFalse($checker->hasActiveSubscription($notSubscribed));
    }

    protected function givenCustomer(): Customer
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    protected function givenSubscription(Customer $customer, string $planCode, SubscriptionStatus $status, string $stripeSubscriptionId): void
    {
        $plan = new Plan();
        $plan->setCode($planCode)->setLabel($planCode)->setStripeProductId('prod_' . $planCode)->setStripePriceId('price_' . $planCode);
        $subscription = new Subscription();
        $subscription->setCustomer($customer)->setPlan($plan)->setStatus($status)->setStripeSubscriptionId($stripeSubscriptionId);
        $this->entityManager->persist($plan);
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }
}
