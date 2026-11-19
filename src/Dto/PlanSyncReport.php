<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Dto;

/**
 * Outcome of a plan synchronization: plan codes created, updated, deactivated, and Stripe price ids skipped.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class PlanSyncReport
{
    /** @var list<string> */
    protected array $created = [];

    /** @var list<string> */
    protected array $updated = [];

    /** @var list<string> */
    protected array $deactivated = [];

    /** @var list<string> */
    protected array $skipped = [];

    /** Prices of other applications of the Stripe account. */
    protected int $foreign = 0;

    public function addCreated(string $planCode): void
    {
        $this->created[] = $planCode;
    }

    public function addUpdated(string $planCode): void
    {
        $this->updated[] = $planCode;
    }

    public function addDeactivated(string $planCode): void
    {
        $this->deactivated[] = $planCode;
    }

    public function addSkipped(string $stripePriceId): void
    {
        $this->skipped[] = $stripePriceId;
    }

    /**
     * @return list<string>
     */
    public function getCreated(): array
    {
        return $this->created;
    }

    /**
     * @return list<string>
     */
    public function getUpdated(): array
    {
        return $this->updated;
    }

    /**
     * @return list<string>
     */
    public function getDeactivated(): array
    {
        return $this->deactivated;
    }

    /**
     * Stripe prices without lookup_key, which cannot become a plan.
     *
     * @return list<string>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    public function addForeign(): void
    {
        ++$this->foreign;
    }

    public function getForeign(): int
    {
        return $this->foreign;
    }
}
