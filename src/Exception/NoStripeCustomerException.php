<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use RuntimeException;

class NoStripeCustomerException extends RuntimeException
{
    public const MESSAGE = 'The customer is not known to Stripe yet.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
