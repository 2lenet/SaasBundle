<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Lle\SaasBundle\LleSaasBundle;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use Lle\SaasBundle\Tests\Fixtures\Webhook\RecordingStripeEventHandler;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal application kernel used to check the bundle in a real container, on an in-memory SQLite database.
 */
class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ROUTE_CHECKOUT_SUCCESS = 'app_billing_success';
    public const ROUTE_CHECKOUT_CANCEL = 'app_pricing';
    public const ROUTE_BILLING_PORTAL_RETURN = 'app_account';
    public const BUNDLE_ROUTES_PREFIX = '/saas';
    public const WEBHOOK_SECRET = 'whsec_kernel';
    public const APP_ID = 'app_kernel';
    /** Environment where the CSRF protection is active (off elsewhere: the tests post without token). */
    public const ENVIRONMENT_CSRF = 'csrf';
    public const DATABASE_URL_ENV = 'LLE_SAAS_TEST_DATABASE_URL';
    public const DEFAULT_DATABASE_URL = 'sqlite:///:memory:';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new LleSaasBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/lle_saas_bundle/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/lle_saas_bundle/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        // keeps the default stderr logger of the kernel quiet during the tests
        $container->services()->set('logger', NullLogger::class);
        // in-memory SQLite by default; the CI also runs the suite on MySQL and PostgreSQL
        $container->parameters()->set('env(' . self::DATABASE_URL_ENV . ')', self::DEFAULT_DATABASE_URL);
        // application handler, tagged through autoconfiguration like in a real app
        $container->services()->set(RecordingStripeEventHandler::class)->autowire()->autoconfigure()->public();
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // off by default (the functional tests post without token), checked in the "csrf" environment
            'csrf_protection' => $this->environment === self::ENVIRONMENT_CSRF,
            'translator' => ['default_path' => '%kernel.cache_dir%/translations', 'fallbacks' => ['en']],
        ]);
        $container->extension('security', [
            'providers' => [
                'users' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'main' => ['lazy' => true, 'provider' => 'users'],
            ],
        ]);
        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(' . self::DATABASE_URL_ENV . ')%',
            ],
            'orm' => [
                'auto_mapping' => true,
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
                'mappings' => [
                    'Fixtures' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => dirname(__DIR__) . '/Fixtures/Entity',
                        'prefix' => 'Lle\\SaasBundle\\Tests\\Fixtures\\Entity',
                    ],
                ],
            ],
        ]);
        $container->extension('lle_saas', [
            'user_class' => User::class,
            'routes' => [
                'checkout_success' => self::ROUTE_CHECKOUT_SUCCESS,
                'checkout_cancel' => self::ROUTE_CHECKOUT_CANCEL,
                'billing_portal_return' => self::ROUTE_BILLING_PORTAL_RETURN,
            ],
            'stripe' => [
                'secret_key' => 'sk_test_kernel',
                'webhook_secret' => self::WEBHOOK_SECRET,
                'app_id' => self::APP_ID,
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@LleSaasBundle/Resources/config/routes.yaml')->prefix(self::BUNDLE_ROUTES_PREFIX);
        // application pages the bundle redirects to
        $routes->add(self::ROUTE_CHECKOUT_SUCCESS, '/billing/success');
        $routes->add(self::ROUTE_CHECKOUT_CANCEL, '/pricing');
        $routes->add(self::ROUTE_BILLING_PORTAL_RETURN, '/account');
    }
}
