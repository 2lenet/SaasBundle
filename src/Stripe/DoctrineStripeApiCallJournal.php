<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Entity\StripeApiCall;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the journal with a direct insert rather than persist()/flush(): flushing here would also write the pending
 * changes of the application in the middle of its own work.
 *
 * While a transaction is open, the entries are kept and written once it is over (release(), called by the bundle
 * after its own transactions, and at the end of the request / command): an entry written inside the transaction
 * would be lost on rollback, and on PostgreSQL a failing insert would abort the whole transaction.
 *
 * Columns and types come from the Doctrine metadata, so the naming strategy of the application is respected.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class DoctrineStripeApiCallJournal implements StripeApiCallJournalInterface, EventSubscriberInterface
{
    /** @var list<StripeApiCall> */
    protected array $pending = [];

    public function __construct(
        protected EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'release',
            ConsoleEvents::TERMINATE => 'release',
        ];
    }

    public function write(StripeApiCall $call): void
    {
        $this->pending[] = $call;
        $this->release();
    }

    public function release(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $call) {
            $this->insert($call);
        }
    }

    protected function insert(StripeApiCall $call): void
    {
        $metadata = $this->entityManager->getClassMetadata(StripeApiCall::class);

        $data = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }
            $column = $metadata->getColumnName($field);
            $data[$column] = $metadata->getFieldValue($call, $field);
            $types[$column] = $metadata->getTypeOfField($field) ?? '';
        }

        $this->entityManager->getConnection()->insert($metadata->getTableName(), $data, $types);
    }
}
