<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Stripe;

use Lle\SaasBundle\Stripe\StripeTime;
use PHPUnit\Framework\TestCase;

class StripeTimeTest extends TestCase
{
    public const TIMESTAMP = 1_763_000_000;

    protected string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Paris');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testDateIsInTheTimeZoneOfTheApplication(): void
    {
        $date = StripeTime::toDateTime(self::TIMESTAMP);

        $this->assertSame(self::TIMESTAMP, $date?->getTimestamp());
        $this->assertSame('Europe/Paris', $date->getTimezone()->getName());
    }

    public function testNullStaysNull(): void
    {
        $this->assertNull(StripeTime::toDateTime(null));
    }
}
