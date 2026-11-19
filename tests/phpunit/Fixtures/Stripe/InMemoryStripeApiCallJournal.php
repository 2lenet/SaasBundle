<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Fixtures\Stripe;

use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Stripe\StripeApiCallJournalInterface;
use RuntimeException;

class InMemoryStripeApiCallJournal implements StripeApiCallJournalInterface
{
    /** @var list<StripeApiCall> */
    public array $calls = [];

    public bool $broken = false;

    public function write(StripeApiCall $call): void
    {
        if ($this->broken) {
            throw new RuntimeException('Journal storage unavailable');
        }

        $this->calls[] = $call;
    }

    public function release(): void
    {
    }
}
