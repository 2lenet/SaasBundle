<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Command;

use Lle\SaasBundle\Command\ReconcileCommand;
use Lle\SaasBundle\Dto\ReconcileReport;
use Lle\SaasBundle\Service\SubscriptionReconciler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReconcileCommandTest extends TestCase
{
    public function testFailsWhileDifferencesRemain(): void
    {
        $report = new ReconcileReport();
        $report->addMissing('sub_missing', 'active');
        $report->addDrifted('sub_drifted', 'status active → past_due');
        $report->addOrphan('sub_orphan');
        $reconciler = $this->createMock(SubscriptionReconciler::class);
        $reconciler->expects($this->once())->method('reconcile')->with(false)->willReturn($report);
        $tester = new CommandTester(new ReconcileCommand($reconciler));

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('sub_missing', $display);
        $this->assertStringContainsString('status active → past_due', $display);
        $this->assertStringContainsString(ReconcileCommand::PROBLEM_ORPHAN, $display);
    }

    public function testSucceedsWhenEverythingIsFixed(): void
    {
        $report = new ReconcileReport();
        $report->addMissing('sub_missing', 'active');
        $report->addFixed('sub_missing');
        $reconciler = $this->createMock(SubscriptionReconciler::class);
        $reconciler->expects($this->once())->method('reconcile')->with(true)->willReturn($report);
        $tester = new CommandTester(new ReconcileCommand($reconciler));

        $exitCode = $tester->execute(['--' . ReconcileCommand::OPTION_FIX => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }
}
