<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook;

/**
 * Stripe event types handled by the bundle. Endpoint configuration in Stripe: at least these types.
 */
final class StripeEventType
{
    public const CHECKOUT_SESSION_COMPLETED = 'checkout.session.completed';
    public const CUSTOMER_SUBSCRIPTION_CREATED = 'customer.subscription.created';
    public const CUSTOMER_SUBSCRIPTION_UPDATED = 'customer.subscription.updated';
    public const CUSTOMER_SUBSCRIPTION_DELETED = 'customer.subscription.deleted';
    public const CUSTOMER_SUBSCRIPTION_PAUSED = 'customer.subscription.paused';
    public const CUSTOMER_SUBSCRIPTION_RESUMED = 'customer.subscription.resumed';
    public const CUSTOMER_UPDATED = 'customer.updated';
    public const CUSTOMER_DELETED = 'customer.deleted';
    public const INVOICE_PAID = 'invoice.paid';
    public const INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';
    /** Every "product.*" event. */
    public const PRODUCT_PREFIX = 'product.';
    /** Every "price.*" event. */
    public const PRICE_PREFIX = 'price.';

    public const SUBSCRIPTION_TYPES = [
        self::CUSTOMER_SUBSCRIPTION_CREATED,
        self::CUSTOMER_SUBSCRIPTION_UPDATED,
        self::CUSTOMER_SUBSCRIPTION_DELETED,
        self::CUSTOMER_SUBSCRIPTION_PAUSED,
        self::CUSTOMER_SUBSCRIPTION_RESUMED,
    ];

    private function __construct()
    {
    }
}
