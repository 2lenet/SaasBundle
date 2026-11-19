<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Stripe;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Repository\StripeApiCallRepository;
use Lle\SaasBundle\Stripe\DoctrineStripeApiCallJournal;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;

class DoctrineStripeApiCallJournalTest extends DatabaseTestCase
{
    public function testWritesTheCallWithoutFlushingPendingApplicationChanges(): void
    {
        $pendingCustomer = new Customer();
        $pendingCustomer->setName(CustomerTest::CUSTOMER_NAME);
        $this->entityManager->persist($pendingCustomer);
        $call = new StripeApiCall();
        $call
            ->setOperation('customers.create')
            ->setSuccess(true)
            ->setHttpStatus(200)
            ->setDurationMs(85)
            ->setStripeRequestId('req_1')
            ->setRequestPayload(['name' => 'ACME']);
        $journal = $this->service(DoctrineStripeApiCallJournal::class);

        $journal->write($call);

        $connection = $this->entityManager->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM lle_saas_customer'));
        $this->entityManager->clear();
        $stored = $this->service(StripeApiCallRepository::class)->findAll();
        $this->assertCount(1, $stored);
        $this->assertSame('customers.create', $stored[0]->getOperation());
        $this->assertSame('req_1', $stored[0]->getStripeRequestId());
        $this->assertSame(85, $stored[0]->getDurationMs());
        $this->assertSame(['name' => 'ACME'], $stored[0]->getRequestPayload());
    }

    public function testCallMadeInsideATransactionSurvivesItsRollback(): void
    {
        $journal = $this->service(DoctrineStripeApiCallJournal::class);
        $connection = $this->entityManager->getConnection();
        $call = (new StripeApiCall())->setOperation('customers.create')->setSuccess(true);

        $connection->beginTransaction();
        $journal->write($call);
        $countInsideTransaction = (int) $connection->fetchOne('SELECT COUNT(*) FROM lle_saas_stripe_api_call');
        $connection->rollBack();
        $journal->release();

        $this->assertSame(0, $countInsideTransaction);
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM lle_saas_stripe_api_call'));
    }

    public function testPendingCallsAreWrittenAtTheEndOfTheRequestOrCommand(): void
    {
        $this->assertSame(
            ['kernel.terminate' => 'release', 'console.terminate' => 'release'],
            DoctrineStripeApiCallJournal::getSubscribedEvents(),
        );
    }
}
