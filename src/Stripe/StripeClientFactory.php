<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Stripe\StripeClient;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeClientFactory
{
    /** Automatic retries of the SDK on network errors, made safe by idempotency keys. */
    public const MAX_NETWORK_RETRIES = 2;

    public function __construct(
        protected string $secretKey,
        protected ?string $apiVersion,
    ) {
    }

    public function create(): StripeClient
    {
        $config = [
            'api_key' => $this->secretKey,
            'max_network_retries' => self::MAX_NETWORK_RETRIES,
        ];
        if ($this->apiVersion !== null) {
            $config['stripe_version'] = $this->apiVersion;
        }

        return new StripeClient($config);
    }
}
