<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use RuntimeException;

/**
 * The Stripe object of a webhook event belongs to another application of the same Stripe account: the event is
 * journaled as "ignored" (answered 200, not retried).
 */
class ForeignStripeObjectException extends RuntimeException
{
    public const MESSAGE = 'Stripe object of another application: ';

    public function __construct(string $stripeObjectId)
    {
        parent::__construct(self::MESSAGE . $stripeObjectId);
    }
}
