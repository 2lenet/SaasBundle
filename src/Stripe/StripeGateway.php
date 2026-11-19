<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Price;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Subscription;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeGateway implements StripeGatewayInterface
{
    public const OPERATION_CUSTOMERS_CREATE = 'customers.create';
    public const OPERATION_CUSTOMERS_UPDATE = 'customers.update';
    public const OPERATION_CUSTOMERS_RETRIEVE = 'customers.retrieve';
    public const OPERATION_PRICES_LIST = 'prices.all';
    public const OPERATION_CHECKOUT_SESSIONS_CREATE = 'checkout.sessions.create';
    public const OPERATION_BILLING_PORTAL_SESSIONS_CREATE = 'billing_portal.sessions.create';
    public const OPERATION_SUBSCRIPTIONS_RETRIEVE = 'subscriptions.retrieve';
    public const OPERATION_SUBSCRIPTIONS_LIST = 'subscriptions.all';
    public const SUBSCRIPTION_STATUS_ALL = 'all';
    public const PARAM_ID = 'id';

    /** The SDK adds a random key to the retried POST requests; an explicit key is only useful when it is stable. */
    public const OPTION_IDEMPOTENCY_KEY = 'idempotency_key';
    public const PAGE_SIZE = 100;
    public const PRICE_TYPE_RECURRING = 'recurring';
    public const EXPAND_PRODUCT = 'data.product';

    public function __construct(
        protected StripeClient $client,
        protected StripeApiCallRecorder $recorder,
    ) {
    }

    public function createCustomer(array $params, ?string $idempotencyKey = null): Customer
    {
        $options = $idempotencyKey === null ? [] : [self::OPTION_IDEMPOTENCY_KEY => $idempotencyKey];

        return $this->recorder->record(
            self::OPERATION_CUSTOMERS_CREATE,
            $params,
            fn (): Customer => $this->client->customers->create($params, $options),
            null,
            $idempotencyKey,
        );
    }

    public function updateCustomer(string $stripeCustomerId, array $params): Customer
    {
        return $this->recorder->record(
            self::OPERATION_CUSTOMERS_UPDATE,
            $params,
            fn (): Customer => $this->client->customers->update($stripeCustomerId, $params),
            $stripeCustomerId,
        );
    }

    public function retrieveCustomer(string $stripeCustomerId): Customer
    {
        return $this->recorder->record(
            self::OPERATION_CUSTOMERS_RETRIEVE,
            [self::PARAM_ID => $stripeCustomerId],
            fn (): Customer => $this->client->customers->retrieve($stripeCustomerId),
            $stripeCustomerId,
        );
    }

    public function listActivePrices(): array
    {
        /** @var list<Price> $prices */
        $prices = $this->paginate(
            self::OPERATION_PRICES_LIST,
            ['active' => true, 'type' => self::PRICE_TYPE_RECURRING, 'expand' => [self::EXPAND_PRODUCT]],
            fn (array $params): Collection => $this->client->prices->all($params),
        );

        return $prices;
    }

    public function listSubscriptions(): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->paginate(
            self::OPERATION_SUBSCRIPTIONS_LIST,
            ['status' => self::SUBSCRIPTION_STATUS_ALL],
            fn (array $params): Collection => $this->client->subscriptions->all($params),
        );

        return $subscriptions;
    }

    public function listCustomerSubscriptions(string $stripeCustomerId): array
    {
        /** @var list<Subscription> $subscriptions */
        $subscriptions = $this->paginate(
            self::OPERATION_SUBSCRIPTIONS_LIST,
            [self::PARAM_CUSTOMER => $stripeCustomerId, 'status' => self::SUBSCRIPTION_STATUS_ALL],
            fn (array $params): Collection => $this->client->subscriptions->all($params),
            $stripeCustomerId,
        );

        return $subscriptions;
    }

    public function createCheckoutSession(array $params): CheckoutSession
    {
        return $this->recorder->record(
            self::OPERATION_CHECKOUT_SESSIONS_CREATE,
            $params,
            fn (): CheckoutSession => $this->client->checkout->sessions->create($params),
            $this->stripeCustomerId($params),
        );
    }

    public function createBillingPortalSession(array $params): BillingPortalSession
    {
        return $this->recorder->record(
            self::OPERATION_BILLING_PORTAL_SESSIONS_CREATE,
            $params,
            fn (): BillingPortalSession => $this->client->billingPortal->sessions->create($params),
            $this->stripeCustomerId($params),
        );
    }

    public function retrieveSubscription(string $stripeSubscriptionId): Subscription
    {
        return $this->recorder->record(
            self::OPERATION_SUBSCRIPTIONS_RETRIEVE,
            [self::PARAM_ID => $stripeSubscriptionId],
            fn (): Subscription => $this->client->subscriptions->retrieve($stripeSubscriptionId),
        );
    }

    /**
     * Follows the pages of a Stripe list, each page being one journaled call.
     *
     * @param array<string, mixed> $params
     * @param callable(array<string, mixed>): Collection<covariant StripeObject> $call
     *
     * @return list<StripeObject>
     */
    protected function paginate(string $operation, array $params, callable $call, ?string $stripeCustomerId = null): array
    {
        $items = [];
        $startingAfter = null;
        do {
            $pageParams = $params + ['limit' => self::PAGE_SIZE];
            if ($startingAfter !== null) {
                $pageParams['starting_after'] = $startingAfter;
            }

            /** @var Collection<StripeObject> $page */
            $page = $this->recorder->record($operation, $pageParams, static fn (): Collection => $call($pageParams), $stripeCustomerId);
            foreach ($page->data as $item) {
                $items[] = $item;
                $startingAfter = $item->id;
            }
        } while ($page->has_more && $startingAfter !== null);

        return $items;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function stripeCustomerId(array $params): ?string
    {
        $customer = $params[self::PARAM_CUSTOMER] ?? null;

        return is_string($customer) ? $customer : null;
    }
}
