<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Event;

use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;

/**
 * An existing subscription changed (status, plan, quantity, period, cancellation at period end).
 */
class SubscriptionUpdatedEvent extends SubscriptionEvent
{
    public function __construct(
        Subscription $subscription,
        protected SubscriptionStatus $previousStatus,
        protected ?Plan $previousPlan,
    ) {
        parent::__construct($subscription);
    }

    public function getPreviousStatus(): SubscriptionStatus
    {
        return $this->previousStatus;
    }

    public function getPreviousPlan(): ?Plan
    {
        return $this->previousPlan;
    }

    public function hasPlanChanged(): bool
    {
        return $this->previousPlan !== $this->subscription->getPlan();
    }
}
