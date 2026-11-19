<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Event;

/**
 * A payment attempt failed: the subscription usually goes "past_due" while Stripe retries.
 */
class PaymentFailedEvent extends InvoiceEvent
{
}
