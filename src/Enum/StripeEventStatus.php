<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Enum;

/**
 * Processing status of a received Stripe webhook event.
 */
enum StripeEventStatus: string
{
    /** Stored, not processed yet. */
    case Received = 'received';
    /** Being processed (claimed by one delivery: a concurrent one gets a 409 and is sent again by Stripe). */
    case Processing = 'processing';

    /** Handled successfully. */
    case Processed = 'processed';
    /** No handler for this event type: kept for monitoring only. */
    case Ignored = 'ignored';
    /** A handler threw: Stripe will retry, the event can be replayed. */
    case Failed = 'failed';
}
