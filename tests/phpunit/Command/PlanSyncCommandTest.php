<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Command;

use Lle\SaasBundle\Command\PlanSyncCommand;
use Lle\SaasBundle\Dto\PlanSyncReport;
use Lle\SaasBundle\Service\PlanSynchronizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PlanSyncCommandTest extends TestCase
{
    public function testDisplaysTheSynchronizationReport(): void
    {
        $report = new PlanSyncReport();
        $report->addCreated('starter');
        $report->addUpdated('pro');
        $report->addDeactivated('legacy');
        $report->addSkipped('price_orphan');
        $synchronizer = $this->createStub(PlanSynchronizer::class);
        $synchronizer->method('synchronize')->willReturn($report);
        $tester = new CommandTester(new PlanSyncCommand($synchronizer));

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('starter', $display);
        $this->assertStringContainsString('pro', $display);
        $this->assertStringContainsString('legacy', $display);
        $this->assertStringContainsString('price_orphan', $display);
        $this->assertStringContainsString('lookup_key', $display);
    }
}
