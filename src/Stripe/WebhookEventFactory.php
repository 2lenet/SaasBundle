<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Lle\SaasBundle\Exception\InvalidWebhookException;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Checks the "Stripe-Signature" header of a webhook request with the endpoint secret, then builds the event.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class WebhookEventFactory
{
    /** Maximum age of a signature, protects against replayed requests. */
    public const TOLERANCE_SECONDS = Webhook::DEFAULT_TOLERANCE;

    public function __construct(
        protected string $webhookSecret,
    ) {
    }

    /**
     * @throws InvalidWebhookException
     */
    public function create(string $payload, string $signature): Event
    {
        try {
            return Webhook::constructEvent($payload, $signature, $this->webhookSecret, self::TOLERANCE_SECONDS);
        } catch (SignatureVerificationException | UnexpectedValueException $exception) {
            throw new InvalidWebhookException($exception->getMessage(), $exception);
        }
    }
}
