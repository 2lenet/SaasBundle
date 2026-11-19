<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use RuntimeException;

class PlanNotAvailableException extends RuntimeException
{
    public const MESSAGE = 'This plan is not on sale.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
