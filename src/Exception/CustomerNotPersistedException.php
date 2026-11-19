<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use LogicException;

class CustomerNotPersistedException extends LogicException
{
    public const MESSAGE = 'The customer must be persisted (have an id) before being synchronized with Stripe.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
