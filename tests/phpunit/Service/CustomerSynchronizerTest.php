<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Exception\CustomerNotPersistedException;
use Lle\SaasBundle\Service\CustomerSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Stripe\InMemoryStripeApiCallJournal;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Stripe\Customer as StripeCustomer;
use Stripe\Exception\ApiConnectionException;

class CustomerSynchronizerTest extends DatabaseTestCase
{
    public const APP_ID = 'app_test';
    public const STRIPE_CUSTOMER_ID = 'cus_sync';
    public const BILLING_EMAIL = 'billing@acme.test';

    public function testCreatesTheStripeCustomerWithAStableIdempotencyKey(): void
    {
        $customer = $this->givenCustomer();
        $customerId = (int) $customer->getId();
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('createCustomer')
            ->with($this->expectedParams($customerId), 'lle_saas_' . self::APP_ID . '_customer_create_' . $customerId)
            ->willReturn(StripeCustomer::constructFrom(['id' => self::STRIPE_CUSTOMER_ID]));
        $gateway->expects($this->never())->method('updateCustomer');

        $this->synchronizer($gateway)->synchronize($customer);

        $this->assertSame(self::STRIPE_CUSTOMER_ID, $customer->getStripeCustomerId());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $this->storedStripeCustomerId($customerId));
    }

    public function testDoesNotFlushThePendingChangesOfTheApplication(): void
    {
        $customer = $this->givenCustomer();
        $customer->setName('Renamed, not flushed yet');
        $gateway = $this->createStub(StripeGatewayInterface::class);
        $gateway->method('createCustomer')->willReturn(StripeCustomer::constructFrom(['id' => self::STRIPE_CUSTOMER_ID]));

        $this->synchronizer($gateway)->synchronize($customer);

        $storedName = $this->entityManager->getConnection()->fetchOne('SELECT name FROM lle_saas_customer WHERE id = ?', [$customer->getId()]);
        $this->assertSame(CustomerTest::CUSTOMER_NAME, $storedName);
        $changeSet = $this->changeSet($customer);
        $this->assertArrayHasKey('name', $changeSet);
        $this->assertArrayNotHasKey(CustomerSynchronizer::FIELD_STRIPE_CUSTOMER_ID, $changeSet);
    }

    public function testReusesTheStripeCustomerCreatedMeanwhileByAnotherRequest(): void
    {
        $customer = $this->givenCustomer();
        $this->entityManager->getConnection()->update(
            'lle_saas_customer',
            ['stripe_customer_id' => self::STRIPE_CUSTOMER_ID],
            ['id' => $customer->getId()],
        );
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('createCustomer');

        $this->synchronizer($gateway)->synchronize($customer);

        $this->assertSame(self::STRIPE_CUSTOMER_ID, $customer->getStripeCustomerId());
    }

    public function testStripeFailureStoresNothing(): void
    {
        $customer = $this->givenCustomer();
        $gateway = $this->createStub(StripeGatewayInterface::class);
        $gateway->method('createCustomer')->willThrowException(new ApiConnectionException('Stripe unreachable'));
        $synchronizer = $this->synchronizer($gateway);

        $caught = null;
        try {
            $synchronizer->synchronize($customer);
        } catch (ApiConnectionException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertNull($customer->getStripeCustomerId());
        $this->assertNull($this->storedStripeCustomerId((int) $customer->getId()));
        $this->assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    public function testUpdatesAnAlreadyLinkedStripeCustomer(): void
    {
        $customer = $this->givenCustomer();
        $customer->setStripeCustomerId(self::STRIPE_CUSTOMER_ID);
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('createCustomer');
        $gateway->expects($this->once())
            ->method('updateCustomer')
            ->with(self::STRIPE_CUSTOMER_ID, $this->expectedParams((int) $customer->getId()))
            ->willReturn(StripeCustomer::constructFrom(['id' => self::STRIPE_CUSTOMER_ID]));

        $this->synchronizer($gateway)->synchronize($customer);
    }

    public function testRefusesACustomerNotYetPersisted(): void
    {
        $customer = (new Customer())->setName(CustomerTest::CUSTOMER_NAME);
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('createCustomer');
        $synchronizer = $this->synchronizer($gateway);

        $this->expectException(CustomerNotPersistedException::class);

        $synchronizer->synchronize($customer);
    }

    protected function synchronizer(StripeGatewayInterface $gateway): CustomerSynchronizer
    {
        return new CustomerSynchronizer($gateway, $this->entityManager, new StripeAppScope(self::APP_ID), new InMemoryStripeApiCallJournal());
    }

    protected function givenCustomer(): Customer
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setBillingEmail(self::BILLING_EMAIL);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    protected function storedStripeCustomerId(int $customerId): ?string
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT stripe_customer_id FROM lle_saas_customer WHERE id = ?', [$customerId]);

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function changeSet(Customer $customer): array
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getEntityChangeSet($customer);
    }

    /**
     * @return array<string, mixed>
     */
    protected function expectedParams(int $customerId): array
    {
        return [
            'name' => CustomerTest::CUSTOMER_NAME,
            'email' => self::BILLING_EMAIL,
            'metadata' => [
                CustomerSynchronizer::METADATA_CUSTOMER_ID => (string) $customerId,
                StripeAppScope::METADATA_APP => self::APP_ID,
            ],
        ];
    }
}
