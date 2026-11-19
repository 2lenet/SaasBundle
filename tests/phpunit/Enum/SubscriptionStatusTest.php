<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Enum;

use Lle\SaasBundle\Enum\SubscriptionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SubscriptionStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{SubscriptionStatus, bool}>
     */
    public static function accessProvider(): iterable
    {
        yield 'active' => [SubscriptionStatus::Active, true];
        yield 'trialing' => [SubscriptionStatus::Trialing, true];
        yield 'past_due keeps access during dunning' => [SubscriptionStatus::PastDue, true];
        yield 'incomplete' => [SubscriptionStatus::Incomplete, false];
        yield 'incomplete_expired' => [SubscriptionStatus::IncompleteExpired, false];
        yield 'unpaid' => [SubscriptionStatus::Unpaid, false];
        yield 'canceled' => [SubscriptionStatus::Canceled, false];
        yield 'paused' => [SubscriptionStatus::Paused, false];
    }

    #[DataProvider('accessProvider')]
    public function testGrantsAccess(SubscriptionStatus $status, bool $expected): void
    {
        $this->assertSame($expected, $status->grantsAccess());
    }

    public function testTerminalStatuses(): void
    {
        $terminal = array_filter(
            SubscriptionStatus::cases(),
            static fn (SubscriptionStatus $status): bool => $status->isTerminal()
        );

        $this->assertSame(
            [SubscriptionStatus::IncompleteExpired, SubscriptionStatus::Canceled],
            array_values($terminal)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stripeStatusProvider(): iterable
    {
        $stripeStatuses = ['incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'];
        foreach ($stripeStatuses as $stripeStatus) {
            yield $stripeStatus => [$stripeStatus];
        }
    }

    #[DataProvider('stripeStatusProvider')]
    public function testEveryStripeStatusIsKnown(string $stripeStatus): void
    {
        $this->assertInstanceOf(SubscriptionStatus::class, SubscriptionStatus::tryFrom($stripeStatus));
    }
}
