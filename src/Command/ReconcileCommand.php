<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Command;

use Lle\SaasBundle\Dto\ReconcileReport;
use Lle\SaasBundle\Service\SubscriptionReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exits with a failure while differences remain: can run as a monitoring cron.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
#[AsCommand(name: self::NAME, description: 'Compares the local subscriptions with Stripe, and corrects them with --fix')]
class ReconcileCommand extends Command
{
    public const NAME = 'lle:saas:reconcile';
    public const OPTION_FIX = 'fix';
    public const PROBLEM_MISSING = 'missing locally';
    public const PROBLEM_DRIFTED = 'different';
    public const PROBLEM_ORPHAN = 'unknown to Stripe';
    public const PROBLEM_FAILED = 'fix failed';

    public function __construct(
        protected SubscriptionReconciler $subscriptionReconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(self::OPTION_FIX, null, InputOption::VALUE_NONE, 'Synchronize the missing and different subscriptions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->subscriptionReconciler->reconcile($input->getOption(self::OPTION_FIX) === true);

        $rows = $this->rows($report);
        if ($rows !== []) {
            $io->table(['Subscription', 'Problem', 'Detail'], $rows);
        }
        if ($report->getFixed() !== []) {
            $io->note('Fixed: ' . implode(', ', $report->getFixed()));
        }
        if ($report->getForeign() > 0) {
            $io->comment($report->getForeign() . ' Stripe subscription(s) of customers unknown to this application ignored.');
        }

        if ($report->hasUnresolved()) {
            $io->error('Local subscriptions differ from Stripe.');

            return Command::FAILURE;
        }

        $io->success('Local subscriptions match Stripe.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{string, string, string}>
     */
    protected function rows(ReconcileReport $report): array
    {
        $rows = [];
        foreach ($report->getMissing() as $stripeSubscriptionId => $status) {
            $rows[] = [$stripeSubscriptionId, self::PROBLEM_MISSING, $status];
        }
        foreach ($report->getDrifted() as $stripeSubscriptionId => $differences) {
            $rows[] = [$stripeSubscriptionId, self::PROBLEM_DRIFTED, $differences];
        }
        foreach ($report->getOrphans() as $stripeSubscriptionId) {
            $rows[] = [$stripeSubscriptionId, self::PROBLEM_ORPHAN, ''];
        }
        foreach ($report->getFailed() as $stripeSubscriptionId => $error) {
            $rows[] = [$stripeSubscriptionId, self::PROBLEM_FAILED, $error];
        }

        return $rows;
    }
}
