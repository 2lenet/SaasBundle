<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook\Handler;

use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Webhook\StripeEventHandlerInterface;
use Lle\SaasBundle\Webhook\StripeEventType;
use Stripe\Event;

/**
 * Keeps the local subscription in line with Stripe.
 *
 * Stripe does not guarantee the order of the events: the subscription is fetched again rather than copied from the
 * payload, so an old event delivered late cannot overwrite a newer state.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class SubscriptionEventHandler implements StripeEventHandlerInterface
{
    public function __construct(
        protected StripeGatewayInterface $gateway,
        protected SubscriptionSynchronizer $subscriptionSynchronizer,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return in_array($eventType, StripeEventType::SUBSCRIPTION_TYPES, true);
    }

    public function handle(Event $event): void
    {
        $stripeSubscriptionId = $event->data->object->id;
        if (!is_string($stripeSubscriptionId)) {
            return;
        }

        $this->subscriptionSynchronizer->synchronize($this->gateway->retrieveSubscription($stripeSubscriptionId));
    }
}
