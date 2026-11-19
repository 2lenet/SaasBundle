<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Enum;

/**
 * Status of a Stripe subscription, values identical to the Stripe API.
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Paused = 'paused';

    /**
     * Statuses giving access to the service. "past_due" keeps access while Stripe retries the payment.
     *
     * @return list<self>
     */
    public static function accessGranting(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }

    public function grantsAccess(): bool
    {
        return in_array($this, self::accessGranting(), true);
    }

    /**
     * A terminal subscription can no longer be reactivated in Stripe.
     */
    public function isTerminal(): bool
    {
        return $this === self::IncompleteExpired || $this === self::Canceled;
    }
}
