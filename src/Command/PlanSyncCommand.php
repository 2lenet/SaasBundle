<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Command;

use Lle\SaasBundle\Service\PlanSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[AsCommand(name: self::NAME, description: 'Synchronizes the local plans with the recurring prices on sale in Stripe')]
class PlanSyncCommand extends Command
{
    public const NAME = 'lle:saas:plan:sync';
    public const LIST_SEPARATOR = ', ';
    public const EMPTY_LIST = '-';

    public function __construct(
        protected PlanSynchronizer $planSynchronizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->planSynchronizer->synchronize();

        $io->table(['Result', 'Plans'], [
            ['Created', $this->format($report->getCreated())],
            ['Updated', $this->format($report->getUpdated())],
            ['Deactivated', $this->format($report->getDeactivated())],
        ]);

        if ($report->getSkipped() !== []) {
            $io->warning(
                'Stripe prices without lookup_key were skipped (set a lookup_key to sell them): '
                . $this->format($report->getSkipped())
            );
        }

        if ($report->getForeign() > 0) {
            $io->comment(
                $report->getForeign() . ' price(s) of other applications ignored (metadata "' . StripeAppScope::METADATA_APP
                . '" on the product or the price).'
            );
        }

        $io->success('Plans synchronized with Stripe.');

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $values
     */
    protected function format(array $values): string
    {
        return $values === [] ? self::EMPTY_LIST : implode(self::LIST_SEPARATOR, $values);
    }
}
