<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Monitoring;

use Lle\SaasBundle\Monitoring\Duration;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public const ELAPSED_MILLISECONDS = 5;

    public function testMillisecondsSinceAHighResolutionStart(): void
    {
        $start = hrtime(true) - self::ELAPSED_MILLISECONDS * Duration::NANOSECONDS_PER_MILLISECOND;

        $elapsed = Duration::millisecondsSince($start);

        $this->assertGreaterThanOrEqual(self::ELAPSED_MILLISECONDS, $elapsed);
        $this->assertLessThan(self::ELAPSED_MILLISECONDS + 1000, $elapsed);
    }

    public function testStartIsTheCurrentHighResolutionTime(): void
    {
        $before = hrtime(true);

        $start = Duration::start();

        $this->assertGreaterThanOrEqual($before, $start);
    }
}
