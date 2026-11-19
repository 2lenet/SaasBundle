<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Lle\SaasBundle\Entity\StripeApiCall;

/**
 * Storage of the Stripe API calls journal.
 */
interface StripeApiCallJournalInterface
{
    public function write(StripeApiCall $call): void;

    /**
     * Writes the entries kept while a database transaction was open (no effect while it still is).
     */
    public function release(): void;
}
