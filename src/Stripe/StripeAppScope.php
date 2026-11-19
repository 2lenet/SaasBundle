<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Stripe\StripeObject;

/**
 * Several applications may share one Stripe account: every object the bundle creates in Stripe (customer, Checkout
 * session, subscription) carries the "lle_saas_app" metadata, and the bundle only takes over the objects carrying the
 * id of its application (lle_saas.stripe.app_id).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeAppScope
{
    public const METADATA_APP = 'lle_saas_app';

    public function __construct(
        protected string $appId,
    ) {
    }

    public function getAppId(): string
    {
        return $this->appId;
    }

    /**
     * @return array<string, string>
     */
    public function metadata(): array
    {
        return [self::METADATA_APP => $this->appId];
    }

    /**
     * The metadata of a Stripe object says it belongs to this application.
     */
    public function owns(?StripeObject $metadata): bool
    {
        return $metadata !== null
            && $metadata->offsetExists(self::METADATA_APP)
            && $metadata[self::METADATA_APP] === $this->appId;
    }
}
