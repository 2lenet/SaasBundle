<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Stripe timestamps (Unix, UTC) as dates in the time zone of the application, like the other dates of the bundle:
 * Doctrine stores the wall-clock time without converting.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
final class StripeTime
{
    private function __construct()
    {
    }

    public static function toDateTime(?int $timestamp): ?DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
