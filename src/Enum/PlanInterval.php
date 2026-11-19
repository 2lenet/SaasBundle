<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Enum;

/**
 * Billing interval of a recurring Stripe price, values identical to the Stripe API.
 */
enum PlanInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
