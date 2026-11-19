<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook;

use Stripe\Event;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * Reacts to a Stripe webhook event. Implement it in the application to handle more event types: autoconfiguration
 * registers the service. Several handlers may support the same type.
 *
 * Handlers run in one database transaction, flushed by the processor: an exception rolls back their work, marks the
 * event as failed and asks Stripe to send it again. They must be idempotent (an event can be delivered twice).
 */
#[AutoconfigureTag(self::TAG)]
interface StripeEventHandlerInterface
{
    public const TAG = 'lle_saas.stripe_event_handler';

    public function supports(string $eventType): bool;

    /**
     * @throws Throwable
     */
    public function handle(Event $event): void;
}
