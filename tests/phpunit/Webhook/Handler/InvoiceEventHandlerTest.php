<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Webhook\Handler;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Event\InvoicePaidEvent;
use Lle\SaasBundle\Event\PaymentFailedEvent;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Lle\SaasBundle\Webhook\Handler\InvoiceEventHandler;
use Lle\SaasBundle\Webhook\StripeEventType;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Stripe\ApiRequestor;
use Stripe\Event;
use Stripe\HttpClient\CurlClient;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class InvoiceEventHandlerTest extends DatabaseTestCase
{
    public const INVOICE_ID = 'in_1';
    public const AMOUNT_DUE = 4900;

    /** @var list<object> */
    protected array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        foreach ([InvoicePaidEvent::class, PaymentFailedEvent::class] as $eventClass) {
            $dispatcher->addListener($eventClass, function (object $event): void {
                $this->dispatched[] = $event;
            });
        }
    }

    public function testSupports(): void
    {
        $handler = $this->service(InvoiceEventHandler::class);

        $this->assertTrue($handler->supports(StripeEventType::INVOICE_PAID));
        $this->assertTrue($handler->supports(StripeEventType::INVOICE_PAYMENT_FAILED));
        $this->assertFalse($handler->supports('invoice.created'));
    }

    public function testPaymentFailureIsDispatchedWithTheLocalCustomerAndSubscription(): void
    {
        $this->givenSubscription();

        $this->handle(StripeEventType::INVOICE_PAYMENT_FAILED, [
            'parent' => [
                'type' => 'subscription_details',
                'subscription_details' => ['subscription' => StripeEventPayload::SUBSCRIPTION_ID],
            ],
        ]);

        $this->assertCount(1, $this->dispatched);
        $event = $this->dispatched[0];
        $this->assertInstanceOf(PaymentFailedEvent::class, $event);
        $this->assertSame(self::INVOICE_ID, $event->getInvoice()->id);
        $this->assertSame(StripeEventPayload::STRIPE_CUSTOMER_ID, $event->getCustomer()->getStripeCustomerId());
        $this->assertSame(StripeEventPayload::SUBSCRIPTION_ID, $event->getSubscription()?->getStripeSubscriptionId());
    }

    public function testPaidInvoiceOfAnOlderApiVersionIsDispatched(): void
    {
        $this->givenSubscription();

        $this->handle(StripeEventType::INVOICE_PAID, ['subscription' => StripeEventPayload::SUBSCRIPTION_ID]);

        $event = $this->dispatched[0] ?? null;
        $this->assertInstanceOf(InvoicePaidEvent::class, $event);
        $this->assertSame(StripeEventPayload::SUBSCRIPTION_ID, $event->getSubscription()?->getStripeSubscriptionId());
    }

    public function testSubscriptionNotYetReceivedIsSynchronizedFirst(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->persistAndFlush($customer);
        $stripe = new FakeStripeHttpClient();
        $stripe->queue(StripeEventPayload::subscription());
        ApiRequestor::setHttpClient($stripe);

        try {
            $this->handle(StripeEventType::INVOICE_PAID, ['subscription' => StripeEventPayload::SUBSCRIPTION_ID]);
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }

        $event = $this->dispatched[0] ?? null;
        $this->assertInstanceOf(InvoicePaidEvent::class, $event);
        $this->assertSame(StripeEventPayload::SUBSCRIPTION_ID, $event->getSubscription()?->getStripeSubscriptionId());
        $this->assertNotNull($event->getSubscription()->getId());
    }

    public function testInvoiceOfAnotherApplicationIsForeign(): void
    {
        $this->expectException(ForeignStripeObjectException::class);

        $this->handle(StripeEventType::INVOICE_PAID, []);
    }

    public function testInvoiceWithoutLocalSubscriptionIsDispatchedWithTheCustomer(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $this->persistAndFlush($customer);

        $this->handle(StripeEventType::INVOICE_PAID, []);

        $event = $this->dispatched[0] ?? null;
        $this->assertInstanceOf(InvoicePaidEvent::class, $event);
        $this->assertSame(StripeEventPayload::STRIPE_CUSTOMER_ID, $event->getCustomer()->getStripeCustomerId());
        $this->assertNull($event->getSubscription());
    }

    protected function givenSubscription(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $subscription = new Subscription();
        $subscription->setCustomer($customer)->setStripeSubscriptionId(StripeEventPayload::SUBSCRIPTION_ID);
        $this->persistAndFlush($customer, $subscription);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    protected function handle(string $eventType, array $invoice): void
    {
        $event = Event::constructFrom(StripeEventPayload::event('evt_invoice', $eventType, $invoice + [
            'id' => self::INVOICE_ID,
            'object' => 'invoice',
            'customer' => StripeEventPayload::STRIPE_CUSTOMER_ID,
            'amount_due' => self::AMOUNT_DUE,
        ]));

        $this->service(InvoiceEventHandler::class)->handle($event);
    }
}
