<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Routing;

use Lle\SaasBundle\Crudit\Config\PlanCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeApiCallCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeEventCrudConfig;
use Lle\SaasBundle\Crudit\Config\SubscriptionCrudConfig;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Routing\CruditRouteLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\RouteCollection;

class CruditRouteLoaderTest extends TestCase
{
    public const AVAILABLE = ['index', 'show', 'export'];
    public const REMOVED = ['new', 'edit', 'delete', 'sort', 'autocomplete', 'editdata', 'workflowtransition'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function screens(): iterable
    {
        yield 'plan' => [PlanCrudConfig::SCREEN];
        yield 'subscription' => [SubscriptionCrudConfig::SCREEN];
        yield 'stripe event' => [StripeEventCrudConfig::SCREEN];
        yield 'stripe api call' => [StripeApiCallCrudConfig::SCREEN];
    }

    #[DataProvider('screens')]
    public function testOnlyTheConsultationRoutesExist(string $screen): void
    {
        $routes = $this->load(true);
        $rootRoute = AbstractReadOnlyCrudConfig::ROOT_ROUTE_PREFIX . strtolower($screen);

        foreach (self::AVAILABLE as $action) {
            $this->assertNotNull($routes->get($rootRoute . '_' . $action), $rootRoute . '_' . $action);
        }
        foreach (self::REMOVED as $action) {
            $this->assertNull($routes->get($rootRoute . '_' . $action), $rootRoute . '_' . $action);
        }
    }

    public function testNoRouteWithoutCrudit(): void
    {
        $this->assertCount(0, $this->load(false));
    }

    public function testSupportsItsTypeOnly(): void
    {
        $loader = new CruditRouteLoader(true);

        $this->assertTrue($loader->supports('.', CruditRouteLoader::TYPE));
        $this->assertFalse($loader->supports('.', 'attribute'));
    }

    protected function load(bool $enabled): RouteCollection
    {
        $loader = new CruditRouteLoader($enabled);
        $directoryLoader = new AttributeDirectoryLoader(new FileLocator(), new AttributeRouteControllerLoader());
        $loader->setResolver(new LoaderResolver([$loader, $directoryLoader]));

        return $loader->load('.', CruditRouteLoader::TYPE);
    }
}
