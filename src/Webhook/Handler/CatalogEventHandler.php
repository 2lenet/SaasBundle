<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook\Handler;

use Lle\SaasBundle\Service\PlanSynchronizer;
use Lle\SaasBundle\Webhook\StripeEventHandlerInterface;
use Lle\SaasBundle\Webhook\StripeEventType;
use Stripe\Event;

/**
 * Any change of the Stripe catalog (product or price) resynchronizes the local plans.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class CatalogEventHandler implements StripeEventHandlerInterface
{
    public function __construct(
        protected PlanSynchronizer $planSynchronizer,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return str_starts_with($eventType, StripeEventType::PRODUCT_PREFIX)
            || str_starts_with($eventType, StripeEventType::PRICE_PREFIX);
    }

    public function handle(Event $event): void
    {
        $this->planSynchronizer->synchronize();
    }
}
