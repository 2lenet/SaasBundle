<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Exception\AlreadySubscribedException;
use Lle\SaasBundle\Exception\PlanNotAvailableException;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Service\CheckoutService;
use Lle\SaasBundle\Service\CustomerSynchronizer;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Stripe\StripeAppScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stripe\Checkout\Session;
use Stripe\Subscription as StripeSubscription;

class CheckoutServiceTest extends TestCase
{
    public const CUSTOMER_ID = 3;
    public const STRIPE_CUSTOMER_ID = 'cus_checkout';
    public const PLAN_CODE = 'pro';
    public const APP_ID = 'app_checkout';
    public const STRIPE_PRICE_ID = 'price_pro';
    public const SUCCESS_URL = 'https://app.test/billing/success';
    public const CANCEL_URL = 'https://app.test/pricing?from=checkout';
    public const SESSION_URL = 'https://checkout.stripe.com/c/pay/cs_1';

    public function testCreatesASubscriptionCheckoutSession(): void
    {
        $customer = $this->customer(self::STRIPE_CUSTOMER_ID);
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('createCheckoutSession')
            ->with([
                'mode' => CheckoutService::MODE_SUBSCRIPTION,
                'customer' => self::STRIPE_CUSTOMER_ID,
                'client_reference_id' => (string) self::CUSTOMER_ID,
                'line_items' => [['price' => self::STRIPE_PRICE_ID, 'quantity' => 1]],
                'success_url' => self::SUCCESS_URL . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => self::CANCEL_URL,
                'metadata' => $this->expectedMetadata(),
                'subscription_data' => ['metadata' => $this->expectedMetadata()],
            ])
            ->willReturn(Session::constructFrom(['id' => 'cs_1', 'url' => self::SESSION_URL]));
        $customerSynchronizer = $this->createMock(CustomerSynchronizer::class);
        $customerSynchronizer->expects($this->never())->method('synchronize');
        $service = new CheckoutService($gateway, $customerSynchronizer, $this->subscriptions([]), $this->appScope());

        $session = $service->createSubscriptionSession($customer, $this->plan(true), self::SUCCESS_URL, self::CANCEL_URL);

        $this->assertSame(self::SESSION_URL, $session->url);
    }

    public function testCreatesTheStripeCustomerFirstWhenMissing(): void
    {
        $customer = $this->customer(null);
        $customerSynchronizer = $this->createMock(CustomerSynchronizer::class);
        $customerSynchronizer->expects($this->once())
            ->method('synchronize')
            ->with($customer)
            ->willReturnCallback(static function (Customer $customer): void {
                $customer->setStripeCustomerId(self::STRIPE_CUSTOMER_ID);
            });
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('createCheckoutSession')
            ->with($this->callback(static fn (array $params): bool => $params['customer'] === self::STRIPE_CUSTOMER_ID))
            ->willReturn(Session::constructFrom(['id' => 'cs_1', 'url' => self::SESSION_URL]));
        $service = new CheckoutService($gateway, $customerSynchronizer, $this->subscriptions([]), $this->appScope());

        $service->createSubscriptionSession($customer, $this->plan(true), self::SUCCESS_URL, self::CANCEL_URL);
    }

    public function testRefusesAnInactivePlan(): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('createCheckoutSession');
        $service = new CheckoutService($gateway, $this->createStub(CustomerSynchronizer::class), $this->subscriptions([]), $this->appScope());
        $customer = $this->customer(self::STRIPE_CUSTOMER_ID);
        $plan = $this->plan(false);

        $this->expectException(PlanNotAvailableException::class);

        $service->createSubscriptionSession($customer, $plan, self::SUCCESS_URL, self::CANCEL_URL);
    }

    public function testRefusesACustomerAlreadySubscribed(): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('createCheckoutSession');
        $service = new CheckoutService(
            $gateway,
            $this->createStub(CustomerSynchronizer::class),
            $this->subscriptions([new Subscription()]),
            $this->appScope(),
        );
        $customer = $this->customer(self::STRIPE_CUSTOMER_ID);
        $plan = $this->plan(true);

        $this->expectException(AlreadySubscribedException::class);

        $service->createSubscriptionSession($customer, $plan, self::SUCCESS_URL, self::CANCEL_URL);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function stripeSubscriptionStatuses(): iterable
    {
        yield 'paid in another tab' => ['active', true];
        yield 'payment pending' => ['incomplete', true];
        yield 'unpaid' => ['unpaid', true];
        yield 'paused' => ['paused', true];
        yield 'canceled' => ['canceled', false];
        yield 'expired' => ['incomplete_expired', false];
    }

    #[DataProvider('stripeSubscriptionStatuses')]
    public function testOnlyAnEndedStripeSubscriptionAllowsANewCheckout(string $status, bool $refused): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())->method('listCustomerSubscriptions')
            ->with(self::STRIPE_CUSTOMER_ID)
            ->willReturn([StripeSubscription::constructFrom(['id' => 'sub_1', 'status' => $status])]);
        $gateway->expects($refused ? $this->never() : $this->once())
            ->method('createCheckoutSession')
            ->willReturn(Session::constructFrom(['id' => 'cs_1', 'url' => self::SESSION_URL]));
        $service = new CheckoutService($gateway, $this->createStub(CustomerSynchronizer::class), $this->subscriptions([]), $this->appScope());
        $customer = $this->customer(self::STRIPE_CUSTOMER_ID);
        $plan = $this->plan(true);

        $caught = null;
        try {
            $service->createSubscriptionSession($customer, $plan, self::SUCCESS_URL, self::CANCEL_URL);
        } catch (AlreadySubscribedException $exception) {
            $caught = $exception;
        }

        $this->assertSame($refused, $caught !== null);
    }

    protected function appScope(): StripeAppScope
    {
        return new StripeAppScope(self::APP_ID);
    }

    /**
     * @return array<string, string>
     */
    protected function expectedMetadata(): array
    {
        return [
            CustomerSynchronizer::METADATA_CUSTOMER_ID => (string) self::CUSTOMER_ID,
            CheckoutService::METADATA_PLAN_CODE => self::PLAN_CODE,
            StripeAppScope::METADATA_APP => self::APP_ID,
        ];
    }

    /**
     * @param list<Subscription> $subscriptions
     */
    protected function subscriptions(array $subscriptions): SubscriptionRepository
    {
        $repository = $this->createStub(SubscriptionRepository::class);
        $repository->method('findAccessGrantingForCustomer')->willReturn($subscriptions);

        return $repository;
    }

    protected function customer(?string $stripeCustomerId): Customer
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId($stripeCustomerId);
        (new ReflectionProperty(Customer::class, 'id'))->setValue($customer, self::CUSTOMER_ID);

        return $customer;
    }

    protected function plan(bool $active): Plan
    {
        $plan = new Plan();
        $plan
            ->setCode(self::PLAN_CODE)
            ->setLabel('Pro')
            ->setStripeProductId('prod_pro')
            ->setStripePriceId(self::STRIPE_PRICE_ID)
            ->setActive($active);

        return $plan;
    }
}
