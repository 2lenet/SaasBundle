<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use RuntimeException;

/**
 * A Stripe subscription belongs to a Stripe customer matching no local customer: the event fails and can be replayed
 * once the customer is linked.
 */
class UnknownCustomerException extends RuntimeException
{
    public const MESSAGE = 'No local customer for the Stripe customer ';

    public function __construct(string $stripeCustomerId)
    {
        parent::__construct(self::MESSAGE . $stripeCustomerId);
    }
}
