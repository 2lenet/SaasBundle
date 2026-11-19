<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use RuntimeException;

class AlreadySubscribedException extends RuntimeException
{
    public const MESSAGE = 'The customer already has a subscription giving access to the service: use the billing portal to change it.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
