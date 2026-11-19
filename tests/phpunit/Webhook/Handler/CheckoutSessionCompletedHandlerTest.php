<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Webhook\Handler;

use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Webhook\Handler\CheckoutSessionCompletedHandler;
use Lle\SaasBundle\Webhook\StripeEventType;
use PHPUnit\Framework\TestCase;
use Stripe\Event;
use Stripe\Subscription as StripeSubscription;

class CheckoutSessionCompletedHandlerTest extends TestCase
{
    public const APP_ID = 'app_checkout';

    public function testSupportsOnlyCompletedCheckoutSessions(): void
    {
        $handler = new CheckoutSessionCompletedHandler(
            $this->createStub(StripeGatewayInterface::class),
            $this->createStub(SubscriptionSynchronizer::class),
            $this->appScope(),
        );

        $this->assertTrue($handler->supports(StripeEventType::CHECKOUT_SESSION_COMPLETED));
        $this->assertFalse($handler->supports('checkout.session.expired'));
    }

    public function testSynchronizesTheSubscriptionCreatedByTheCheckout(): void
    {
        $stripeSubscription = StripeSubscription::constructFrom(StripeEventPayload::subscription());
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('retrieveSubscription')
            ->with(StripeEventPayload::SUBSCRIPTION_ID)
            ->willReturn($stripeSubscription);
        $synchronizer = $this->createMock(SubscriptionSynchronizer::class);
        $synchronizer->expects($this->once())->method('synchronize')->with($stripeSubscription);
        $handler = new CheckoutSessionCompletedHandler($gateway, $synchronizer, $this->appScope());

        $handler->handle($this->event('subscription', StripeEventPayload::SUBSCRIPTION_ID));
    }

    public function testIgnoresPaymentSessions(): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('retrieveSubscription');
        $synchronizer = $this->createMock(SubscriptionSynchronizer::class);
        $synchronizer->expects($this->never())->method('synchronize');
        $handler = new CheckoutSessionCompletedHandler($gateway, $synchronizer, $this->appScope());

        $handler->handle($this->event('payment', null));
    }

    public function testSessionOfAnotherApplicationIsForeign(): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('retrieveSubscription');
        $handler = new CheckoutSessionCompletedHandler($gateway, $this->createStub(SubscriptionSynchronizer::class), $this->appScope());
        $event = $this->event('subscription', StripeEventPayload::SUBSCRIPTION_ID, 'another_app');

        $this->expectException(ForeignStripeObjectException::class);

        $handler->handle($event);
    }

    protected function appScope(): StripeAppScope
    {
        return new StripeAppScope(self::APP_ID);
    }

    protected function event(string $mode, ?string $subscriptionId, string $appId = self::APP_ID): Event
    {
        return Event::constructFrom(StripeEventPayload::event('evt_checkout', StripeEventType::CHECKOUT_SESSION_COMPLETED, [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'mode' => $mode,
            'customer' => StripeEventPayload::STRIPE_CUSTOMER_ID,
            'subscription' => $subscriptionId,
            'metadata' => [StripeAppScope::METADATA_APP => $appId],
        ]));
    }
}
