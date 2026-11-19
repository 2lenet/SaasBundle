<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Monitoring;

/**
 * Duration of a journaled operation (webhook processing, Stripe API call), measured with the monotonic clock.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
final class Duration
{
    public const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    private function __construct()
    {
    }

    public static function start(): int|float
    {
        return hrtime(true);
    }

    public static function millisecondsSince(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / self::NANOSECONDS_PER_MILLISECOND);
    }
}
