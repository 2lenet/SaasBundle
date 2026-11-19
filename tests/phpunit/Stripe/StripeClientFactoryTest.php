<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Stripe;

use Lle\SaasBundle\Stripe\StripeClientFactory;
use PHPUnit\Framework\TestCase;
use Stripe\Util\ApiVersion;

class StripeClientFactoryTest extends TestCase
{
    public const SECRET_KEY = 'sk_test_factory';
    public const API_VERSION = '2026-01-28.clover';

    public function testClientUsesTheConfiguredKeyAndRetries(): void
    {
        $factory = new StripeClientFactory(self::SECRET_KEY, null);

        $client = $factory->create();

        $this->assertSame(self::SECRET_KEY, $client->getApiKey());
        $this->assertSame(ApiVersion::CURRENT, $client->getStripeVersion());
        $this->assertSame(StripeClientFactory::MAX_NETWORK_RETRIES, $client->getMaxNetworkRetries());
    }

    public function testApiVersionCanBePinned(): void
    {
        $factory = new StripeClientFactory(self::SECRET_KEY, self::API_VERSION);

        $client = $factory->create();

        $this->assertSame(self::API_VERSION, $client->getStripeVersion());
    }
}
