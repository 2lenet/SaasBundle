<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Stripe;

use Lle\SaasBundle\Stripe\StripeApiCallRecorder;
use Lle\SaasBundle\Stripe\StripeGateway;
use Lle\SaasBundle\Stripe\StripeObjects;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Lle\SaasBundle\Tests\Fixtures\Stripe\InMemoryStripeApiCallJournal;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\CurlClient;
use Stripe\Price;
use Stripe\StripeClient;

class StripeGatewayTest extends TestCase
{
    public const STRIPE_CUSTOMER_ID = 'cus_test_1';
    public const CUSTOMER_PARAMS = ['name' => 'ACME', 'email' => 'billing@acme.test'];
    public const IDEMPOTENCY_KEY = 'lle_saas_app_customer_create_1';

    protected FakeStripeHttpClient $httpClient;
    protected InMemoryStripeApiCallJournal $journal;

    protected function setUp(): void
    {
        $this->httpClient = new FakeStripeHttpClient();
        $this->journal = new InMemoryStripeApiCallJournal();
        ApiRequestor::setHttpClient($this->httpClient);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
    }

    public function testCreateCustomerSendsTheGivenIdempotencyKeyAndIsJournaled(): void
    {
        $this->httpClient->queue(['id' => self::STRIPE_CUSTOMER_ID, 'object' => 'customer', 'name' => 'ACME']);
        $gateway = $this->gateway();

        $customer = $gateway->createCustomer(self::CUSTOMER_PARAMS, self::IDEMPOTENCY_KEY);

        $this->assertSame(self::STRIPE_CUSTOMER_ID, $customer->id);
        $request = $this->httpClient->getRequests()[0];
        $this->assertSame('post', $request['method']);
        $this->assertStringEndsWith('/v1/customers', $request['url']);
        $this->assertSame(self::CUSTOMER_PARAMS, $request['params']);
        $idempotencyKey = $this->httpClient->findHeader(0, 'Idempotency-Key');
        $this->assertSame(self::IDEMPOTENCY_KEY, $idempotencyKey);

        $this->assertCount(1, $this->journal->calls);
        $call = $this->journal->calls[0];
        $this->assertSame(StripeGateway::OPERATION_CUSTOMERS_CREATE, $call->getOperation());
        $this->assertTrue($call->isSuccess());
        $this->assertSame(200, $call->getHttpStatus());
        $this->assertSame(FakeStripeHttpClient::REQUEST_ID_PREFIX . '1', $call->getStripeRequestId());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getResourceId());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getStripeCustomerId());
        $this->assertSame($idempotencyKey, $call->getIdempotencyKey());
        $this->assertSame(self::CUSTOMER_PARAMS, $call->getRequestPayload());
        $this->assertGreaterThanOrEqual(0, $call->getDurationMs());
    }

    public function testUpdateCustomerTargetsTheStripeCustomer(): void
    {
        $this->httpClient->queue(['id' => self::STRIPE_CUSTOMER_ID, 'object' => 'customer']);
        $gateway = $this->gateway();

        $gateway->updateCustomer(self::STRIPE_CUSTOMER_ID, self::CUSTOMER_PARAMS);

        $this->assertStringEndsWith('/v1/customers/' . self::STRIPE_CUSTOMER_ID, $this->httpClient->getRequests()[0]['url']);
        $call = $this->journal->calls[0];
        $this->assertSame(StripeGateway::OPERATION_CUSTOMERS_UPDATE, $call->getOperation());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getStripeCustomerId());
        $this->assertNull($call->getIdempotencyKey());
    }

    public function testStripeErrorIsJournaledThenRethrown(): void
    {
        $this->httpClient->queue([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'message' => 'No such customer: cus_unknown',
            ],
        ], 404);
        $gateway = $this->gateway();

        $caught = null;
        try {
            $gateway->updateCustomer('cus_unknown', self::CUSTOMER_PARAMS);
        } catch (InvalidRequestException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertCount(1, $this->journal->calls);
        $call = $this->journal->calls[0];
        $this->assertFalse($call->isSuccess());
        $this->assertSame(404, $call->getHttpStatus());
        $this->assertSame('invalid_request_error', $call->getErrorType());
        $this->assertSame('resource_missing', $call->getErrorCode());
        $this->assertSame('No such customer: cus_unknown', $call->getErrorMessage());
        $this->assertSame(FakeStripeHttpClient::REQUEST_ID_PREFIX . '1', $call->getStripeRequestId());
        $this->assertSame('cus_unknown', $call->getStripeCustomerId());
    }

    public function testBrokenJournalNeverBreaksTheStripeCall(): void
    {
        $this->journal->broken = true;
        $this->httpClient->queue(['id' => self::STRIPE_CUSTOMER_ID, 'object' => 'customer']);
        $gateway = $this->gateway();

        $customer = $gateway->createCustomer(self::CUSTOMER_PARAMS);

        $this->assertSame(self::STRIPE_CUSTOMER_ID, $customer->id);
    }

    public function testListActivePricesFollowsPagination(): void
    {
        $this->httpClient->queue([
            'object' => 'list',
            'url' => '/v1/prices',
            'has_more' => true,
            'data' => [['id' => 'price_1', 'object' => 'price'], ['id' => 'price_2', 'object' => 'price']],
        ]);
        $this->httpClient->queue([
            'object' => 'list',
            'url' => '/v1/prices',
            'has_more' => false,
            'data' => [['id' => 'price_3', 'object' => 'price']],
        ]);
        $gateway = $this->gateway();

        $prices = $gateway->listActivePrices();

        $this->assertSame(['price_1', 'price_2', 'price_3'], array_map(static fn (Price $price): string => $price->id, $prices));
        $requests = $this->httpClient->getRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('true', $requests[0]['params']['active']); // the SDK encodes booleans for the wire
        $this->assertSame(StripeGateway::PRICE_TYPE_RECURRING, $requests[0]['params']['type']);
        $this->assertSame(['data.product'], $requests[0]['params']['expand']);
        $this->assertArrayNotHasKey('starting_after', $requests[0]['params']);
        $this->assertSame('price_2', $requests[1]['params']['starting_after']);
        $this->assertCount(2, $this->journal->calls);
        $this->assertSame(StripeGateway::OPERATION_PRICES_LIST, $this->journal->calls[1]->getOperation());
    }

    public function testCreateCheckoutSessionIsJournaled(): void
    {
        $this->httpClient->queue([
            'id' => 'cs_test_1',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            'customer' => self::STRIPE_CUSTOMER_ID,
        ]);
        $gateway = $this->gateway();
        $params = ['mode' => 'subscription', 'customer' => self::STRIPE_CUSTOMER_ID];

        $session = $gateway->createCheckoutSession($params);

        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $session->url);
        $this->assertStringEndsWith('/v1/checkout/sessions', $this->httpClient->getRequests()[0]['url']);
        $call = $this->journal->calls[0];
        $this->assertSame(StripeGateway::OPERATION_CHECKOUT_SESSIONS_CREATE, $call->getOperation());
        $this->assertNull($call->getIdempotencyKey());
        $this->assertSame('cs_test_1', $call->getResourceId());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getStripeCustomerId());
    }

    public function testCreateBillingPortalSessionIsJournaled(): void
    {
        $this->httpClient->queue([
            'id' => 'bps_test_1',
            'object' => 'billing_portal.session',
            'url' => 'https://billing.stripe.com/p/session/bps_test_1',
            'customer' => self::STRIPE_CUSTOMER_ID,
        ]);
        $gateway = $this->gateway();

        $session = $gateway->createBillingPortalSession(['customer' => self::STRIPE_CUSTOMER_ID, 'return_url' => 'https://app.test']);

        $this->assertSame('https://billing.stripe.com/p/session/bps_test_1', $session->url);
        $this->assertStringEndsWith('/v1/billing_portal/sessions', $this->httpClient->getRequests()[0]['url']);
        $call = $this->journal->calls[0];
        $this->assertSame(StripeGateway::OPERATION_BILLING_PORTAL_SESSIONS_CREATE, $call->getOperation());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getStripeCustomerId());
    }

    public function testRetrieveSubscriptionIsJournaledWithItsCustomer(): void
    {
        $this->httpClient->queue(['id' => 'sub_1', 'object' => 'subscription', 'customer' => self::STRIPE_CUSTOMER_ID, 'status' => 'active']);
        $gateway = $this->gateway();

        $subscription = $gateway->retrieveSubscription('sub_1');

        $this->assertSame('active', $subscription->status);
        $request = $this->httpClient->getRequests()[0];
        $this->assertSame('get', $request['method']);
        $this->assertStringEndsWith('/v1/subscriptions/sub_1', $request['url']);
        $call = $this->journal->calls[0];
        $this->assertSame(StripeGateway::OPERATION_SUBSCRIPTIONS_RETRIEVE, $call->getOperation());
        $this->assertSame('sub_1', $call->getResourceId());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getStripeCustomerId());
    }

    public function testListSubscriptionsAsksForEveryStatus(): void
    {
        $this->httpClient->queue([
            'object' => 'list',
            'url' => '/v1/subscriptions',
            'has_more' => false,
            'data' => [['id' => 'sub_1', 'object' => 'subscription'], ['id' => 'sub_2', 'object' => 'subscription']],
        ]);
        $gateway = $this->gateway();

        $subscriptions = $gateway->listSubscriptions();

        $this->assertCount(2, $subscriptions);
        $this->assertSame(StripeGateway::SUBSCRIPTION_STATUS_ALL, $this->httpClient->getRequests()[0]['params']['status']);
        $this->assertSame(StripeGateway::OPERATION_SUBSCRIPTIONS_LIST, $this->journal->calls[0]->getOperation());
    }

    public function testRetrieveCustomerIsJournaled(): void
    {
        $this->httpClient->queue(['id' => self::STRIPE_CUSTOMER_ID, 'object' => 'customer', 'deleted' => true]);
        $gateway = $this->gateway();

        $customer = $gateway->retrieveCustomer(self::STRIPE_CUSTOMER_ID);

        $this->assertTrue(StripeObjects::isDeleted($customer));
        $this->assertStringEndsWith('/v1/customers/' . self::STRIPE_CUSTOMER_ID, $this->httpClient->getRequests()[0]['url']);
        $call = $this->journal->calls[0];
        $this->assertSame(StripeGateway::OPERATION_CUSTOMERS_RETRIEVE, $call->getOperation());
        $this->assertSame(self::STRIPE_CUSTOMER_ID, $call->getStripeCustomerId());
    }

    protected function gateway(): StripeGateway
    {
        $client = new StripeClient(['api_key' => 'sk_test_gateway', 'max_network_retries' => 0]);

        return new StripeGateway($client, new StripeApiCallRecorder($this->journal, new NullLogger()));
    }
}
