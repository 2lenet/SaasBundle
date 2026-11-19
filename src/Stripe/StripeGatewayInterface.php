<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Subscription;

/**
 * Single entry point of the bundle to the Stripe API: every call goes through it, and is journaled.
 */
interface StripeGatewayInterface
{
    /** Request parameter holding the Stripe customer id. */
    public const PARAM_CUSTOMER = StripeObjects::CUSTOMER_FIELD;

    /**
     * @param array<string, mixed> $params
     * @param string|null $idempotencyKey stable key: Stripe answers the same customer to a repeated creation
     *
     * @throws ApiErrorException
     */
    public function createCustomer(array $params, ?string $idempotencyKey = null): Customer;

    /**
     * @param array<string, mixed> $params
     *
     * @throws ApiErrorException
     */
    public function updateCustomer(string $stripeCustomerId, array $params): Customer;

    /**
     * Current state of a Stripe customer; a deleted one is returned with "deleted" set (see StripeObjects::isDeleted()).
     *
     * @throws ApiErrorException
     */
    public function retrieveCustomer(string $stripeCustomerId): Customer;

    /**
     * Active recurring prices, with their product expanded, all pages included.
     *
     * @return list<Price>
     *
     * @throws ApiErrorException
     */
    public function listActivePrices(): array;

    /**
     * @param array<string, mixed> $params
     *
     * @throws ApiErrorException
     */
    public function createCheckoutSession(array $params): CheckoutSession;

    /**
     * @param array<string, mixed> $params
     *
     * @throws ApiErrorException
     */
    public function createBillingPortalSession(array $params): BillingPortalSession;

    /**
     * @throws ApiErrorException
     */
    public function retrieveSubscription(string $stripeSubscriptionId): Subscription;

    /**
     * Every subscription of the Stripe account, whatever its status, all pages included.
     *
     * @return list<Subscription>
     *
     * @throws ApiErrorException
     */
    public function listSubscriptions(): array;

    /**
     * Subscriptions of one Stripe customer, whatever their status.
     *
     * @return list<Subscription>
     *
     * @throws ApiErrorException
     */
    public function listCustomerSubscriptions(string $stripeCustomerId): array;
}
