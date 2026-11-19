<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Fixtures\Stripe;

use Stripe\WebhookSignature;

/**
 * Builds Stripe webhook payloads (and their signature) as Stripe sends them.
 */
class StripeEventPayload
{
    public const API_VERSION = '2026-10-29.clover';
    public const CREATED = 1_763_000_000;
    public const PERIOD_START = 1_763_000_000;
    public const PERIOD_END = 1_765_592_000;
    public const SUBSCRIPTION_ID = 'sub_1';
    public const STRIPE_CUSTOMER_ID = 'cus_1';
    public const STRIPE_PRICE_ID = 'price_pro';
    public const QUANTITY = 2;

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public static function event(string $eventId, string $type, array $object): array
    {
        return [
            'id' => $eventId,
            'object' => 'event',
            'type' => $type,
            'livemode' => false,
            'api_version' => self::API_VERSION,
            'created' => self::CREATED,
            'data' => ['object' => $object],
        ];
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function json(string $eventId, string $type, array $object): string
    {
        return (string) json_encode(self::event($eventId, $type, $object));
    }

    public static function sign(string $payload, string $secret): string
    {
        return WebhookSignature::generateSignatureHeader($payload, $secret);
    }

    /**
     * Stripe subscription with the periods on its item (API versions since 2025-03-31.basil).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function subscription(array $overrides = []): array
    {
        return array_replace([
            'id' => self::SUBSCRIPTION_ID,
            'object' => 'subscription',
            'customer' => self::STRIPE_CUSTOMER_ID,
            'status' => 'active',
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'metadata' => [],
            'items' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'si_1',
                        'object' => 'subscription_item',
                        'quantity' => self::QUANTITY,
                        'current_period_start' => self::PERIOD_START,
                        'current_period_end' => self::PERIOD_END,
                        'price' => ['id' => self::STRIPE_PRICE_ID, 'object' => 'price', 'lookup_key' => null],
                    ],
                ],
            ],
        ], $overrides);
    }
}
