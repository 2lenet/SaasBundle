<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Dispatches the events of the bundle (SubscriptionActivatedEvent, PaymentFailedEvent...) to the application.
 *
 * While a webhook is processed, the events are kept and dispatched once its transaction is committed: a listener
 * never acts (e-mail, provisioning) on changes that are then rolled back, and never twice because a later handler
 * failed. Outside a webhook (reconcile...), they are dispatched at once.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class LifecycleEventDispatcher implements ResetInterface
{
    /** @var list<object> */
    protected array $deferred = [];

    protected bool $deferring = false;

    public function __construct(
        protected EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function dispatch(object $event): void
    {
        if ($this->deferring) {
            $this->deferred[] = $event;

            return;
        }

        $this->eventDispatcher->dispatch($event);
    }

    public function defer(): void
    {
        $this->deferring = true;
        $this->deferred = [];
    }

    /**
     * Dispatches the kept events. A failing listener does not prevent the next ones: its exception is returned.
     *
     * @return list<Throwable>
     */
    public function release(): array
    {
        $events = $this->deferred;
        $this->reset();

        $failures = [];
        foreach ($events as $event) {
            try {
                $this->eventDispatcher->dispatch($event);
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        return $failures;
    }

    /**
     * Drops the kept events (the transaction was rolled back).
     */
    public function discard(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->deferring = false;
        $this->deferred = [];
    }
}
