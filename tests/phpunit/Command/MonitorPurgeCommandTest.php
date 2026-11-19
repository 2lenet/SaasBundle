<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Lle\SaasBundle\Command\MonitorPurgeCommand;
use Lle\SaasBundle\Dto\PurgeReport;
use Lle\SaasBundle\Service\MonitoringPurger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class MonitorPurgeCommandTest extends TestCase
{
    public function testPurgesWithTheGivenRetention(): void
    {
        $purger = $this->createMock(MonitoringPurger::class);
        $purger->expects($this->once())
            ->method('purge')
            ->with(30, true)
            ->willReturn(new PurgeReport(new DateTimeImmutable('2026-10-19'), 12, 34));
        $tester = new CommandTester(new MonitorPurgeCommand($purger));

        $exitCode = $tester->execute([
            '--' . MonitorPurgeCommand::OPTION_DAYS => '30',
            '--' . MonitorPurgeCommand::OPTION_INCLUDE_FAILED => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('12 webhook event(s) and 34 API call(s)', $tester->getDisplay());
    }

    public function testInvalidRetention(): void
    {
        $purger = $this->createStub(MonitoringPurger::class);
        $purger->method('purge')->willThrowException(new InvalidArgumentException(MonitoringPurger::INVALID_DAYS));
        $tester = new CommandTester(new MonitorPurgeCommand($purger));

        $exitCode = $tester->execute(['--' . MonitorPurgeCommand::OPTION_DAYS => '0']);

        $this->assertSame(Command::INVALID, $exitCode);
    }
}
