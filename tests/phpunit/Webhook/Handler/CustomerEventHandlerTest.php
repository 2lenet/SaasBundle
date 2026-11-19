<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Webhook\Handler;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Lle\SaasBundle\Webhook\Handler\CustomerEventHandler;
use Lle\SaasBundle\Webhook\StripeEventType;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Stripe\ApiRequestor;
use Stripe\Event;
use Stripe\HttpClient\CurlClient;

class CustomerEventHandlerTest extends DatabaseTestCase
{
    public const OLD_EMAIL = 'old@acme.test';
    public const NEW_EMAIL = 'billing@acme.test';
    public const STALE_EMAIL = 'stale@acme.test';

    protected FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function testSupports(): void
    {
        $handler = $this->service(CustomerEventHandler::class);

        $this->assertTrue($handler->supports(StripeEventType::CUSTOMER_UPDATED));
        $this->assertTrue($handler->supports(StripeEventType::CUSTOMER_DELETED));
        $this->assertFalse($handler->supports('customer.created'));
    }

    public function testCurrentBillingEmailIsReadFromStripe(): void
    {
        $customerId = $this->givenCustomer();
        $this->stripe->queue(['id' => StripeEventPayload::STRIPE_CUSTOMER_ID, 'object' => 'customer', 'email' => self::NEW_EMAIL]);

        $this->handle(StripeEventType::CUSTOMER_UPDATED, StripeEventPayload::STRIPE_CUSTOMER_ID);

        $customer = $this->entityManager->find(Customer::class, $customerId);
        $this->assertSame(self::NEW_EMAIL, $customer?->getBillingEmail());
    }

    public function testDeletedStripeCustomerIsUnlinked(): void
    {
        $customerId = $this->givenCustomer();

        $this->handle(StripeEventType::CUSTOMER_DELETED, StripeEventPayload::STRIPE_CUSTOMER_ID);

        $customer = $this->entityManager->find(Customer::class, $customerId);
        $this->assertNotNull($customer);
        $this->assertNull($customer->getStripeCustomerId());
        $this->assertSame(self::OLD_EMAIL, $customer->getBillingEmail());
    }

    public function testCustomerDeletedMeanwhileIsUnlinked(): void
    {
        $customerId = $this->givenCustomer();
        $this->stripe->queue(['id' => StripeEventPayload::STRIPE_CUSTOMER_ID, 'object' => 'customer', 'deleted' => true]);

        $this->handle(StripeEventType::CUSTOMER_UPDATED, StripeEventPayload::STRIPE_CUSTOMER_ID);

        $customer = $this->entityManager->find(Customer::class, $customerId);
        $this->assertNull($customer?->getStripeCustomerId());
    }

    public function testStripeCustomerOfAnotherApplicationIsForeign(): void
    {
        $this->givenCustomer();

        $this->expectException(ForeignStripeObjectException::class);

        $this->handle(StripeEventType::CUSTOMER_UPDATED, 'cus_elsewhere');
    }

    protected function givenCustomer(): ?int
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)
            ->setBillingEmail(self::OLD_EMAIL)
            ->setStripeCustomerId(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->persistAndFlush($customer);

        return $customer->getId();
    }

    protected function handle(string $eventType, string $stripeCustomerId): void
    {
        $event = Event::constructFrom(StripeEventPayload::event('evt_customer', $eventType, [
            'id' => $stripeCustomerId,
            'object' => 'customer',
            'email' => self::STALE_EMAIL,
        ]));

        $this->service(CustomerEventHandler::class)->handle($event);
        // the webhook processor flushes the work of the handlers in its transaction
        $this->entityManager->flush();
        $this->entityManager->clear();
    }
}
