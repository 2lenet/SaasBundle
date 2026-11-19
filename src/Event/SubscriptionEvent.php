<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Event;

use Lle\SaasBundle\Entity\Subscription;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Change of a local subscription, dispatched while a Stripe webhook is processed (inside its transaction).
 */
abstract class SubscriptionEvent extends Event
{
    public function __construct(
        protected Subscription $subscription,
    ) {
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }
}
