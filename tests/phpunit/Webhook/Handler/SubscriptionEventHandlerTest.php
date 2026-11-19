<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Webhook\Handler;

use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Webhook\Handler\SubscriptionEventHandler;
use Lle\SaasBundle\Webhook\StripeEventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\Event;
use Stripe\Subscription as StripeSubscription;

class SubscriptionEventHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function eventTypes(): iterable
    {
        yield 'created' => [StripeEventType::CUSTOMER_SUBSCRIPTION_CREATED, true];
        yield 'updated' => [StripeEventType::CUSTOMER_SUBSCRIPTION_UPDATED, true];
        yield 'deleted' => [StripeEventType::CUSTOMER_SUBSCRIPTION_DELETED, true];
        yield 'paused' => [StripeEventType::CUSTOMER_SUBSCRIPTION_PAUSED, true];
        yield 'resumed' => [StripeEventType::CUSTOMER_SUBSCRIPTION_RESUMED, true];
        yield 'customer' => [StripeEventType::CUSTOMER_UPDATED, false];
    }

    #[DataProvider('eventTypes')]
    public function testSupports(string $eventType, bool $expected): void
    {
        $handler = new SubscriptionEventHandler(
            $this->createStub(StripeGatewayInterface::class),
            $this->createStub(SubscriptionSynchronizer::class),
        );

        $this->assertSame($expected, $handler->supports($eventType));
    }

    public function testSynchronizesTheCurrentStateFetchedFromStripe(): void
    {
        // the payload may be outdated (events are not ordered): the handler works on a fresh copy
        $fresh = StripeSubscription::constructFrom(StripeEventPayload::subscription(['status' => 'past_due']));
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('retrieveSubscription')
            ->with(StripeEventPayload::SUBSCRIPTION_ID)
            ->willReturn($fresh);
        $synchronizer = $this->createMock(SubscriptionSynchronizer::class);
        $synchronizer->expects($this->once())->method('synchronize')->with($fresh);
        $handler = new SubscriptionEventHandler($gateway, $synchronizer);
        $event = Event::constructFrom(StripeEventPayload::event(
            'evt_sub',
            StripeEventType::CUSTOMER_SUBSCRIPTION_UPDATED,
            StripeEventPayload::subscription(),
        ));

        $handler->handle($event);
    }
}
