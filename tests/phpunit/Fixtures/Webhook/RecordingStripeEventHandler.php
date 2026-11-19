<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Fixtures\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Service\LifecycleEventDispatcher;
use Lle\SaasBundle\Webhook\StripeEventHandlerInterface;
use RuntimeException;
use Stripe\Event;
use Throwable;

/**
 * Application handler registered in the test kernel: writes a plan, dispatches an event, then fails on demand.
 */
class RecordingStripeEventHandler implements StripeEventHandlerInterface
{
    public const TYPE = 'lle_test.recorded';
    public const FAILURE = 'Handler failure';
    public const PLAN_CODE = 'written_by_handler';

    public int $calls = 0;
    public bool $failing = false;

    /** @var list<Throwable> thrown one per call, before the "failing" behavior */
    public array $exceptions = [];

    /** Event of the bundle dispatched by each call. */
    public ?object $lifecycleEvent = null;

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected LifecycleEventDispatcher $lifecycleEvents,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return $eventType === self::TYPE;
    }

    public function handle(Event $event): void
    {
        ++$this->calls;

        $plan = new Plan();
        $plan->setCode(self::PLAN_CODE)->setLabel(self::PLAN_CODE)->setStripeProductId('prod_handler')->setStripePriceId('price_handler');
        $this->entityManager->persist($plan);
        if ($this->lifecycleEvent !== null) {
            $this->lifecycleEvents->dispatch($this->lifecycleEvent);
        }

        $exception = array_shift($this->exceptions);
        if ($exception !== null) {
            throw $exception;
        }
        if ($this->failing) {
            throw new RuntimeException(self::FAILURE);
        }
    }
}
