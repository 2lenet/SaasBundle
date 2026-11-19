<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook\Handler;

use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Service\CheckoutService;
use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Webhook\StripeEventHandlerInterface;
use Lle\SaasBundle\Webhook\StripeEventType;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Subscription as StripeSubscription;

/**
 * Creates the local subscription as soon as the checkout is completed, without waiting for
 * "customer.subscription.created" (both lead to the same synchronization).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class CheckoutSessionCompletedHandler implements StripeEventHandlerInterface
{
    public function __construct(
        protected StripeGatewayInterface $gateway,
        protected SubscriptionSynchronizer $subscriptionSynchronizer,
        protected StripeAppScope $appScope,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return $eventType === StripeEventType::CHECKOUT_SESSION_COMPLETED;
    }

    public function handle(Event $event): void
    {
        $session = $event->data->object;
        if (!$session instanceof Session || $session->mode !== CheckoutService::MODE_SUBSCRIPTION) {
            return;
        }
        if (!$this->appScope->owns($session->metadata)) {
            throw new ForeignStripeObjectException($session->id);
        }

        $subscription = $session->subscription;
        $stripeSubscriptionId = $subscription instanceof StripeSubscription ? $subscription->id : $subscription;
        if ($stripeSubscriptionId === null) {
            return;
        }

        $this->subscriptionSynchronizer->synchronize($this->gateway->retrieveSubscription($stripeSubscriptionId));
    }
}
