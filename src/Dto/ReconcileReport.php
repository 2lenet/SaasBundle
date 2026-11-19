<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Dto;

/**
 * Differences between the local subscriptions and Stripe, found by SubscriptionReconciler.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class ReconcileReport
{
    /** @var array<string, string> Stripe subscriptions of a local customer, missing locally (id => status) */
    protected array $missing = [];

    /** @var array<string, string> local subscriptions different from Stripe (id => differences) */
    protected array $drifted = [];

    /** @var list<string> local subscriptions still running, unknown to Stripe */
    protected array $orphans = [];

    /** @var list<string> subscriptions corrected by --fix */
    protected array $fixed = [];

    /** @var array<string, string> corrections that failed (id => error) */
    protected array $failed = [];

    /** Stripe subscriptions of customers unknown to the application (account shared with other applications). */
    protected int $foreign = 0;

    public function addMissing(string $stripeSubscriptionId, string $status): void
    {
        $this->missing[$stripeSubscriptionId] = $status;
    }

    public function addDrifted(string $stripeSubscriptionId, string $differences): void
    {
        $this->drifted[$stripeSubscriptionId] = $differences;
    }

    public function addOrphan(string $stripeSubscriptionId): void
    {
        $this->orphans[] = $stripeSubscriptionId;
    }

    public function addFixed(string $stripeSubscriptionId): void
    {
        $this->fixed[] = $stripeSubscriptionId;
    }

    public function addFailed(string $stripeSubscriptionId, string $error): void
    {
        $this->failed[$stripeSubscriptionId] = $error;
    }

    public function addForeign(): void
    {
        ++$this->foreign;
    }

    /**
     * @return array<string, string>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * @return array<string, string>
     */
    public function getDrifted(): array
    {
        return $this->drifted;
    }

    /**
     * @return list<string>
     */
    public function getOrphans(): array
    {
        return $this->orphans;
    }

    /**
     * @return list<string>
     */
    public function getFixed(): array
    {
        return $this->fixed;
    }

    /**
     * @return array<string, string>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    public function getForeign(): int
    {
        return $this->foreign;
    }

    /**
     * Something is still different from Stripe after the run.
     */
    public function hasUnresolved(): bool
    {
        $found = count($this->missing) + count($this->drifted);

        return $found > count($this->fixed) || $this->failed !== [] || $this->orphans !== [];
    }
}
