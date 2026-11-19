<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Command;

use InvalidArgumentException;
use Lle\SaasBundle\Service\MonitoringPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[AsCommand(name: self::NAME, description: 'Deletes the old entries of the Stripe journals (webhook events, API calls)')]
class MonitorPurgeCommand extends Command
{
    public const NAME = 'lle:saas:monitor:purge';
    public const OPTION_DAYS = 'days';
    public const OPTION_INCLUDE_FAILED = 'include-failed';
    public const DATE_FORMAT = 'Y-m-d H:i';

    public function __construct(
        protected MonitoringPurger $monitoringPurger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                self::OPTION_DAYS,
                null,
                InputOption::VALUE_REQUIRED,
                'Retention, in days',
                (string) MonitoringPurger::DEFAULT_DAYS,
            )
            ->addOption(self::OPTION_INCLUDE_FAILED, null, InputOption::VALUE_NONE, 'Also delete the failed webhook events');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $report = $this->monitoringPurger->purge(
                (int) $input->getOption(self::OPTION_DAYS),
                $input->getOption(self::OPTION_INCLUDE_FAILED) === true,
            );
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->success(sprintf(
            'Before %s: %d webhook event(s) and %d API call(s) deleted.',
            $report->getBefore()->format(self::DATE_FORMAT),
            $report->getDeletedEvents(),
            $report->getDeletedApiCalls(),
        ));

        return Command::SUCCESS;
    }
}
