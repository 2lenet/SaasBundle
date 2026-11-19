<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Doctrine\ORM\OptimisticLockException;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Event\SubscriptionActivatedEvent;
use Lle\SaasBundle\Event\SubscriptionCanceledEvent;
use Lle\SaasBundle\Event\SubscriptionUpdatedEvent;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Exception\UnknownCustomerException;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Service\CheckoutService;
use Lle\SaasBundle\Service\CustomerSynchronizer;
use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\Subscription as StripeSubscription;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SubscriptionSynchronizerTest extends DatabaseTestCase
{
    public const PLAN_CODE = 'pro';
    public const ENDED_AT = 1_764_000_000;

    /** @var list<object> */
    protected array $dispatched = [];

    protected FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->stripe);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        foreach ([SubscriptionActivatedEvent::class, SubscriptionUpdatedEvent::class, SubscriptionCanceledEvent::class] as $eventClass) {
            $dispatcher->addListener($eventClass, function (object $event): void {
                $this->dispatched[] = $event;
            });
        }
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function testCreatesTheLocalSubscription(): void
    {
        $customer = $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->givenPlan();

        $this->synchronizer()->synchronize($this->stripeSubscription());

        $subscription = $this->reload();
        $this->assertSame($customer->getId(), $subscription->getCustomer()?->getId());
        $this->assertSame(self::PLAN_CODE, $subscription->getPlan()?->getCode());
        $this->assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        $this->assertSame(StripeEventPayload::QUANTITY, $subscription->getQuantity());
        $this->assertSame(StripeEventPayload::PERIOD_START, $subscription->getCurrentPeriodStart()?->getTimestamp());
        $this->assertSame(StripeEventPayload::PERIOD_END, $subscription->getCurrentPeriodEnd()?->getTimestamp());
        $this->assertFalse($subscription->isCancelAtPeriodEnd());
        $this->assertSame([SubscriptionActivatedEvent::class], $this->dispatchedClasses());
    }

    public function testFindsTheCustomerFromTheCheckoutMetadataAndLinksIt(): void
    {
        $customer = $this->givenCustomer(null);
        $this->stripe->queue(['id' => StripeEventPayload::STRIPE_CUSTOMER_ID, 'object' => 'customer']);
        $this->givenPlan();
        $stripeSubscription = $this->stripeSubscription([
            'metadata' => [
                CustomerSynchronizer::METADATA_CUSTOMER_ID => (string) $customer->getId(),
                StripeAppScope::METADATA_APP => TestKernel::APP_ID,
            ],
        ]);

        $this->synchronizer()->synchronize($stripeSubscription);

        $subscription = $this->reload();
        $this->assertSame($customer->getId(), $subscription->getCustomer()?->getId());
        $this->assertSame(StripeEventPayload::STRIPE_CUSTOMER_ID, $subscription->getCustomer()?->getStripeCustomerId());
    }

    public function testDeletedStripeCustomerIsNotLinkedAgain(): void
    {
        $customer = $this->givenCustomer(null);
        $this->stripe->queue(['id' => StripeEventPayload::STRIPE_CUSTOMER_ID, 'object' => 'customer', 'deleted' => true]);
        $stripeSubscription = $this->stripeSubscription([
            'status' => 'canceled',
            'metadata' => [
                CustomerSynchronizer::METADATA_CUSTOMER_ID => (string) $customer->getId(),
                StripeAppScope::METADATA_APP => TestKernel::APP_ID,
            ],
        ]);

        $this->synchronizer()->synchronize($stripeSubscription);

        $subscription = $this->reload();
        $this->assertSame($customer->getId(), $subscription->getCustomer()?->getId());
        $this->assertNull($subscription->getCustomer()?->getStripeCustomerId());
    }

    public function testUnknownStatusKeepsThePreviousOne(): void
    {
        $this->givenSubscription(SubscriptionStatus::Active);

        $this->synchronizer()->synchronize($this->stripeSubscription(['status' => 'status_of_the_future']));

        $this->assertSame(SubscriptionStatus::Active, $this->reload()->getStatus());
    }

    public function testNewSubscriptionWithAnUnknownStatusGivesNoAccess(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);

        $this->synchronizer()->synchronize($this->stripeSubscription(['status' => 'status_of_the_future']));

        $this->assertSame(SubscriptionStatus::Incomplete, $this->reload()->getStatus());
        $this->assertSame([], $this->dispatched);
    }

    public function testScheduledCancellationIsStored(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);

        $this->synchronizer()->synchronize($this->stripeSubscription(['cancel_at' => self::ENDED_AT]));

        $this->assertSame(self::ENDED_AT, $this->reload()->getCancelAt()?->getTimestamp());
    }

    public function testSubscriptionOfAnotherApplicationIsForeign(): void
    {
        $synchronizer = $this->synchronizer();
        $stripeSubscription = $this->stripeSubscription([
            'metadata' => [CustomerSynchronizer::METADATA_CUSTOMER_ID => '1', StripeAppScope::METADATA_APP => 'another_app'],
        ]);
        $this->givenCustomer(null);

        $this->expectException(ForeignStripeObjectException::class);

        $synchronizer->synchronize($stripeSubscription);
    }

    public function testSubscriptionOfThisApplicationWithAnUnknownCustomerFails(): void
    {
        $synchronizer = $this->synchronizer();
        $stripeSubscription = $this->stripeSubscription([
            'metadata' => [CustomerSynchronizer::METADATA_CUSTOMER_ID => '999', StripeAppScope::METADATA_APP => TestKernel::APP_ID],
        ]);

        $this->expectException(UnknownCustomerException::class);

        $synchronizer->synchronize($stripeSubscription);
    }

    public function testPaymentWithAnotherStripeCustomerOfTheCustomerGivesAccess(): void
    {
        $customer = $this->givenCustomer('cus_linked');
        $stripeSubscription = $this->stripeSubscription([
            'customer' => 'cus_other',
            'metadata' => [
                CustomerSynchronizer::METADATA_CUSTOMER_ID => (string) $customer->getId(),
                StripeAppScope::METADATA_APP => TestKernel::APP_ID,
            ],
        ]);

        $this->synchronizer()->synchronize($stripeSubscription);

        $subscription = $this->reload();
        $this->assertSame($customer->getId(), $subscription->getCustomer()?->getId());
        $this->assertSame('cus_linked', $subscription->getCustomer()?->getStripeCustomerId());
    }

    public function testPlanIsKeptWhileThePriceDoesNotChange(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->givenPlan();
        $synchronizer = $this->synchronizer();
        $synchronizer->synchronize($this->stripeSubscription());
        // the lookup_key of the plan moved to a new price: the subscription stays on the old one
        $this->entityManager->getConnection()->update('lle_saas_plan', ['stripe_price_id' => 'price_pro_new'], ['code' => self::PLAN_CODE]);
        $this->entityManager->clear();

        $synchronizer->synchronize($this->stripeSubscription(['status' => 'past_due']));

        $subscription = $this->reload();
        $this->assertSame(self::PLAN_CODE, $subscription->getPlan()?->getCode());
        $this->assertSame(StripeEventPayload::STRIPE_PRICE_ID, $subscription->getStripePriceId());
    }

    public function testCheckoutMetadataIsOnlyUsedAtCreation(): void
    {
        $this->givenSubscription(SubscriptionStatus::Active);
        $this->givenPlan();
        $payload = StripeEventPayload::subscription([
            'metadata' => [CheckoutService::METADATA_PLAN_CODE => self::PLAN_CODE, StripeAppScope::METADATA_APP => TestKernel::APP_ID],
        ]);
        $payload['items']['data'][0]['price'] = ['id' => 'price_unknown', 'object' => 'price', 'lookup_key' => null];

        $this->synchronizer()->synchronize(StripeSubscription::constructFrom($payload));

        $this->assertNull($this->reload()->getPlan());
    }

    public function testSubscriptionFirstSeenAlreadyEndedDispatchesNothing(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);

        $this->synchronizer()->synchronize($this->stripeSubscription(['status' => 'canceled', 'ended_at' => self::ENDED_AT]));

        $this->assertSame(SubscriptionStatus::Canceled, $this->reload()->getStatus());
        $this->assertSame([], $this->dispatched);
    }

    public function testFindsThePlanByLookupKeyWhenThePriceChanged(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->givenPlan();
        $payload = StripeEventPayload::subscription();
        $payload['items']['data'][0]['price'] = ['id' => 'price_new', 'object' => 'price', 'lookup_key' => self::PLAN_CODE];

        $this->synchronizer()->synchronize(StripeSubscription::constructFrom($payload));

        $this->assertSame(self::PLAN_CODE, $this->reload()->getPlan()?->getCode());
    }

    public function testFallsBackToThePeriodOfOlderApiVersions(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $payload = StripeEventPayload::subscription([
            'current_period_start' => StripeEventPayload::PERIOD_START,
            'current_period_end' => StripeEventPayload::PERIOD_END,
        ]);
        unset($payload['items']['data'][0]['current_period_start'], $payload['items']['data'][0]['current_period_end']);

        $this->synchronizer()->synchronize(StripeSubscription::constructFrom($payload));

        $subscription = $this->reload();
        $this->assertNull($subscription->getPlan());
        $this->assertSame(StripeEventPayload::PERIOD_START, $subscription->getCurrentPeriodStart()?->getTimestamp());
        $this->assertSame(StripeEventPayload::PERIOD_END, $subscription->getCurrentPeriodEnd()?->getTimestamp());
    }

    public function testActivationOfAnExistingSubscription(): void
    {
        $this->givenSubscription(SubscriptionStatus::Incomplete);

        $this->synchronizer()->synchronize($this->stripeSubscription());

        $this->assertSame([SubscriptionUpdatedEvent::class, SubscriptionActivatedEvent::class], $this->dispatchedClasses());
        $updated = $this->dispatched[0];
        $this->assertInstanceOf(SubscriptionUpdatedEvent::class, $updated);
        $this->assertSame(SubscriptionStatus::Incomplete, $updated->getPreviousStatus());
        $this->assertSame(SubscriptionStatus::Active, $updated->getSubscription()->getStatus());
    }

    public function testCancellation(): void
    {
        $this->givenSubscription(SubscriptionStatus::Active);

        $this->synchronizer()->synchronize($this->stripeSubscription([
            'status' => 'canceled',
            'canceled_at' => self::ENDED_AT,
            'ended_at' => self::ENDED_AT,
        ]));

        $this->assertSame([SubscriptionUpdatedEvent::class, SubscriptionCanceledEvent::class], $this->dispatchedClasses());
        $subscription = $this->reload();
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->getStatus());
        $this->assertSame(self::ENDED_AT, $subscription->getEndedAt()?->getTimestamp());
        $this->assertSame(self::ENDED_AT, $subscription->getCanceledAt()?->getTimestamp());
    }

    public function testUnchangedSubscriptionDispatchesNothing(): void
    {
        $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $synchronizer = $this->synchronizer();
        $synchronizer->synchronize($this->stripeSubscription());
        $this->dispatched = [];

        $synchronizer->synchronize($this->stripeSubscription());

        $this->assertSame([], $this->dispatched);
    }

    public function testConcurrentUpdateOfTheSubscriptionIsDetected(): void
    {
        $this->givenSubscription(SubscriptionStatus::Active);
        $synchronizer = $this->synchronizer();
        $this->service(SubscriptionRepository::class)->findOneByStripeSubscriptionId(StripeEventPayload::SUBSCRIPTION_ID);
        // another delivery updated the subscription after it was read here
        $this->entityManager->getConnection()->executeStatement('UPDATE lle_saas_subscription SET version = version + 1');
        $stripeSubscription = $this->stripeSubscription(['status' => 'past_due']);

        $this->expectException(OptimisticLockException::class);

        $synchronizer->synchronize($stripeSubscription);
    }

    protected function synchronizer(): SubscriptionSynchronizer
    {
        return $this->service(SubscriptionSynchronizer::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function stripeSubscription(array $overrides = []): StripeSubscription
    {
        return StripeSubscription::constructFrom(StripeEventPayload::subscription($overrides));
    }

    protected function givenCustomer(?string $stripeCustomerId): Customer
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId($stripeCustomerId);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    protected function givenPlan(): void
    {
        $plan = new Plan();
        $plan->setCode(self::PLAN_CODE)->setLabel('Pro')->setStripeProductId('prod_pro')->setStripePriceId(StripeEventPayload::STRIPE_PRICE_ID);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();
    }

    protected function givenSubscription(SubscriptionStatus $status): void
    {
        $customer = $this->givenCustomer(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $subscription = new Subscription();
        $subscription->setCustomer($customer)->setStripeSubscriptionId(StripeEventPayload::SUBSCRIPTION_ID)->setStatus($status);
        $this->persistAndFlush($subscription);
    }

    protected function reload(): Subscription
    {
        $this->entityManager->clear();
        $subscription = $this->service(SubscriptionRepository::class)->findOneByStripeSubscriptionId(StripeEventPayload::SUBSCRIPTION_ID);
        $this->assertNotNull($subscription);

        return $subscription;
    }

    /**
     * @return list<class-string>
     */
    protected function dispatchedClasses(): array
    {
        return array_map(static fn (object $event): string => $event::class, $this->dispatched);
    }
}
