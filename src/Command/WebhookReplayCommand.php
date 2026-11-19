<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Command;

use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Repository\StripeEventRepository;
use Lle\SaasBundle\Webhook\WebhookProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[AsCommand(name: self::NAME, description: 'Processes again stored Stripe webhook events (one by its id, or the failed ones)')]
class WebhookReplayCommand extends Command
{
    public const NAME = 'lle:saas:webhook:replay';
    public const ARGUMENT_EVENT_ID = 'stripe-event-id';
    public const OPTION_FAILED = 'failed';
    public const OPTION_LIMIT = 'limit';

    public function __construct(
        protected WebhookProcessor $webhookProcessor,
        protected StripeEventRepository $stripeEventRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(self::ARGUMENT_EVENT_ID, InputArgument::OPTIONAL, 'Stripe event id (evt_...), replayed whatever its status')
            ->addOption(self::OPTION_FAILED, null, InputOption::VALUE_NONE, 'Replay the failed events, oldest first')
            ->addOption(
                self::OPTION_LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of failed events',
                (string) StripeEventRepository::DEFAULT_LIMIT,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stripeEventId = $input->getArgument(self::ARGUMENT_EVENT_ID);

        if (is_string($stripeEventId) && $stripeEventId !== '') {
            $journal = $this->stripeEventRepository->findOneByStripeEventId($stripeEventId);
            if ($journal === null) {
                $io->error('Unknown Stripe event ' . $stripeEventId);

                return Command::FAILURE;
            }
            $events = [$journal];
        } elseif ($input->getOption(self::OPTION_FAILED) === true) {
            $events = $this->stripeEventRepository->findFailed((int) $input->getOption(self::OPTION_LIMIT));
        } else {
            $io->error('Give a Stripe event id, or --' . self::OPTION_FAILED);

            return Command::INVALID;
        }

        return $this->replay($io, $events);
    }

    /**
     * @param list<StripeEvent> $events
     */
    protected function replay(SymfonyStyle $io, array $events): int
    {
        if ($events === []) {
            $io->success('No event to replay.');

            return Command::SUCCESS;
        }

        $rows = [];
        $failures = 0;
        foreach ($events as $event) {
            $result = $this->webhookProcessor->replay($event);
            if ($result->getStatus() === StripeEventStatus::Failed) {
                ++$failures;
            }
            $rows[] = [$result->getStripeEventId(), $result->getType(), $result->getStatus()->value, $result->getAttempts(), $result->getError()];
        }

        $io->table(['Event', 'Type', 'Status', 'Attempts', 'Error'], $rows);
        if ($failures > 0) {
            $io->error($failures . ' event(s) still failing.');

            return Command::FAILURE;
        }

        $io->success(count($events) . ' event(s) replayed.');

        return Command::SUCCESS;
    }
}
