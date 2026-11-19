<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Doctrine\EntityManagerResetter;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Repository\CustomerRepository;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Service\CustomerSynchronizer;
use Lle\SaasBundle\Service\SubscriptionReconciler;
use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Stripe\Subscription as StripeSubscription;

class SubscriptionReconcilerTest extends DatabaseTestCase
{
    public function testReportsTheSubscriptionMissingLocallyAndCreatesItWithFix(): void
    {
        $this->givenCustomer();
        $stripeSubscriptions = [$this->stripeSubscription([])];

        $report = $this->reconciler($stripeSubscriptions)->reconcile(false);

        $this->assertSame([StripeEventPayload::SUBSCRIPTION_ID => 'active'], $report->getMissing());
        $this->assertTrue($report->hasUnresolved());
        $this->assertNull($this->local());

        $fixed = $this->reconciler($stripeSubscriptions)->reconcile(true);

        $this->assertSame([StripeEventPayload::SUBSCRIPTION_ID], $fixed->getFixed());
        $this->assertFalse($fixed->hasUnresolved());
        $this->assertSame(SubscriptionStatus::Active, $this->local()?->getStatus());
    }

    public function testIgnoresTheSubscriptionsOfOtherApplications(): void
    {
        $report = $this->reconciler([$this->stripeSubscription(['customer' => 'cus_elsewhere'])])->reconcile(true);

        $this->assertSame([], $report->getMissing());
        $this->assertSame(1, $report->getForeign());
        $this->assertFalse($report->hasUnresolved());
    }

    public function testReportsADifferentStatusAndFixesIt(): void
    {
        $this->givenLocalSubscription(SubscriptionStatus::Active);
        $stripeSubscriptions = [$this->stripeSubscription(['status' => 'past_due', 'cancel_at_period_end' => true])];

        $report = $this->reconciler($stripeSubscriptions)->reconcile(true);

        $drifted = $report->getDrifted()[StripeEventPayload::SUBSCRIPTION_ID] ?? '';
        $this->assertStringContainsString('status active → past_due', $drifted);
        $this->assertStringContainsString('cancel_at_period_end → true', $drifted);
        $this->assertStringContainsString('price - → ' . StripeEventPayload::STRIPE_PRICE_ID, $drifted);
        $this->assertFalse($report->hasUnresolved());
        $this->assertSame(SubscriptionStatus::PastDue, $this->local()?->getStatus());
    }

    public function testIgnoresTheEndedSubscriptionsMissingLocally(): void
    {
        $this->givenCustomer();

        $report = $this->reconciler([$this->stripeSubscription(['status' => 'canceled'])])->reconcile(true);

        $this->assertSame([], $report->getMissing());
        $this->assertFalse($report->hasUnresolved());
        $this->assertNull($this->local());
    }

    public function testComparesThePriceOfTheSubscriptionNotThePriceOfItsPlan(): void
    {
        $this->givenLocalSubscription(SubscriptionStatus::Active);
        $this->entityManager->getConnection()->update(
            'lle_saas_subscription',
            ['stripe_price_id' => StripeEventPayload::STRIPE_PRICE_ID],
            ['stripe_subscription_id' => StripeEventPayload::SUBSCRIPTION_ID],
        );

        $report = $this->reconciler([$this->stripeSubscription([])])->reconcile(false);

        $this->assertSame([], $report->getDrifted());
    }

    public function testReportsTheRunningSubscriptionsUnknownToStripe(): void
    {
        $this->givenLocalSubscription(SubscriptionStatus::Active);

        $report = $this->reconciler([])->reconcile(true);

        $this->assertSame([StripeEventPayload::SUBSCRIPTION_ID], $report->getOrphans());
        $this->assertTrue($report->hasUnresolved());
    }

    public function testEndedSubscriptionUnknownToStripeIsNotAnOrphan(): void
    {
        $this->givenLocalSubscription(SubscriptionStatus::Canceled);

        $report = $this->reconciler([])->reconcile(false);

        $this->assertSame([], $report->getOrphans());
    }

    public function testFixOfAnUnknownCustomerFails(): void
    {
        $stripeSubscription = $this->stripeSubscription([
            'customer' => 'cus_new',
            'metadata' => [CustomerSynchronizer::METADATA_CUSTOMER_ID => '999', StripeAppScope::METADATA_APP => TestKernel::APP_ID],
        ]);

        $report = $this->reconciler([$stripeSubscription])->reconcile(true);

        $this->assertArrayHasKey(StripeEventPayload::SUBSCRIPTION_ID, $report->getFailed());
        $this->assertTrue($report->hasUnresolved());
    }

    /**
     * @param list<StripeSubscription> $stripeSubscriptions
     */
    protected function reconciler(array $stripeSubscriptions): SubscriptionReconciler
    {
        $gateway = $this->createStub(StripeGatewayInterface::class);
        $gateway->method('listSubscriptions')->willReturn($stripeSubscriptions);

        return new SubscriptionReconciler(
            $gateway,
            $this->service(SubscriptionRepository::class),
            $this->service(CustomerRepository::class),
            $this->service(SubscriptionSynchronizer::class),
            new StripeAppScope(TestKernel::APP_ID),
            $this->service(EntityManagerResetter::class),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function stripeSubscription(array $overrides): StripeSubscription
    {
        return StripeSubscription::constructFrom(StripeEventPayload::subscription($overrides));
    }

    protected function givenCustomer(): Customer
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    protected function givenLocalSubscription(SubscriptionStatus $status): void
    {
        $subscription = new Subscription();
        $subscription->setCustomer($this->givenCustomer())
            ->setStripeSubscriptionId(StripeEventPayload::SUBSCRIPTION_ID)
            ->setStatus($status);
        $this->persistAndFlush($subscription);
    }

    protected function local(): ?Subscription
    {
        $this->entityManager->clear();

        return $this->service(SubscriptionRepository::class)->findOneByStripeSubscriptionId(StripeEventPayload::SUBSCRIPTION_ID);
    }
}
