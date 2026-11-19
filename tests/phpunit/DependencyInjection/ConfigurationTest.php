<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\DependencyInjection;

use Lle\SaasBundle\DependencyInjection\Configuration;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigurationTest extends TestCase
{
    public const SECRET_KEY = 'sk_test_123';
    public const WEBHOOK_SECRET = 'whsec_123';
    public const API_VERSION = '2026-10-29.clover';
    public const APP_ID = 'rgaa_checker';
    public const ROUTES = [
        'checkout_success' => 'app_billing_success',
        'checkout_cancel' => 'app_pricing',
        'billing_portal_return' => 'app_account',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function minimalConfig(): array
    {
        return [
            'user_class' => User::class,
            'routes' => self::ROUTES,
            'stripe' => [
                'secret_key' => self::SECRET_KEY,
                'webhook_secret' => self::WEBHOOK_SECRET,
                'app_id' => self::APP_ID,
            ],
        ];
    }

    public function testMinimalConfigurationUsesDefaults(): void
    {
        $config = $this->process(self::minimalConfig());

        $this->assertSame(User::class, $config['user_class']);
        $this->assertSame(Customer::class, $config['customer_class']);
        $this->assertSame(self::SECRET_KEY, $config['stripe']['secret_key']);
        $this->assertSame(self::WEBHOOK_SECRET, $config['stripe']['webhook_secret']);
        $this->assertNull($config['stripe']['api_version']);
        $this->assertSame(self::APP_ID, $config['stripe']['app_id']);
        $this->assertSame(self::ROUTES, $config['routes']);
    }

    public function testRedirectRoutesAreRequired(): void
    {
        $input = self::minimalConfig();
        unset($input['routes']['checkout_cancel']);

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testApiVersionCanBePinned(): void
    {
        $input = self::minimalConfig();
        $input['stripe']['api_version'] = self::API_VERSION;

        $config = $this->process($input);

        $this->assertSame(self::API_VERSION, $config['stripe']['api_version']);
    }

    public function testUserClassIsRequired(): void
    {
        $input = self::minimalConfig();
        unset($input['user_class']);

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testUserClassMustImplementSaasUserInterface(): void
    {
        $input = self::minimalConfig();
        $input['user_class'] = Customer::class;

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testCustomerClassMustImplementCustomerInterface(): void
    {
        $input = self::minimalConfig();
        $input['customer_class'] = User::class;

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testSecretKeyIsRequired(): void
    {
        $input = self::minimalConfig();
        unset($input['stripe']['secret_key']);

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testWebhookSecretCannotBeEmpty(): void
    {
        $input = self::minimalConfig();
        $input['stripe']['webhook_secret'] = '';

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testAppIdIsRequired(): void
    {
        $input = self::minimalConfig();
        unset($input['stripe']['app_id']);

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    public function testAppIdMustBeASimpleIdentifier(): void
    {
        $input = self::minimalConfig();
        $input['stripe']['app_id'] = 'RGAA checker!';

        $this->expectException(InvalidConfigurationException::class);

        $this->process($input);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    protected function process(array $config): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$config]);
    }
}
