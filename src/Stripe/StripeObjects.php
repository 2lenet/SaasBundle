<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Stripe\StripeObject;

/**
 * Helpers on the objects returned by the Stripe SDK.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
final class StripeObjects
{
    /** Set to true on a deleted object (a deleted customer can still be retrieved). */
    public const DELETED_FIELD = 'deleted';
    /** Type ("object" field) of a customer. */
    public const CUSTOMER_OBJECT = 'customer';
    /** Field holding the Stripe customer (id or expanded object) of a subscription, invoice, session... */
    public const CUSTOMER_FIELD = 'customer';

    private function __construct()
    {
    }

    public static function isDeleted(StripeObject $object): bool
    {
        return $object->offsetExists(self::DELETED_FIELD) && $object[self::DELETED_FIELD] === true;
    }
}
