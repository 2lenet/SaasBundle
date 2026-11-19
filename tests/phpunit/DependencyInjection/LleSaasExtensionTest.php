<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\DependencyInjection;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Controller\Crudit\PlanController;
use Lle\SaasBundle\Crudit\Config\PlanCrudConfig;
use Lle\SaasBundle\Crudit\CrudMenu\SaasMenuProvider;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Lle\SaasBundle\DependencyInjection\LleSaasExtension;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Security\SaasCredentialWarmup;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use Lle\SaasBundle\Twig\SaasTwigExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class LleSaasExtensionTest extends TestCase
{
    public const CUSTOM_CUSTOMER_CLASS = 'App\\Entity\\Customer';
    public const EXCLUDED_TAG = 'container.excluded';

    public function testAliasIsLleSaas(): void
    {
        $extension = new LleSaasExtension();

        $this->assertSame(LleSaasExtension::ALIAS, $extension->getAlias());
    }

    public function testParametersAreExposed(): void
    {
        $container = new ContainerBuilder();
        $extension = new LleSaasExtension();
        $config = ConfigurationTest::minimalConfig();
        $config['stripe']['api_version'] = ConfigurationTest::API_VERSION;

        $extension->load([$config], $container);

        $this->assertSame(User::class, $container->getParameter(LleSaasExtension::PARAMETER_USER_CLASS));
        $this->assertSame(Customer::class, $container->getParameter(LleSaasExtension::PARAMETER_CUSTOMER_CLASS));
        $this->assertSame(ConfigurationTest::ROUTES, $container->getParameter(LleSaasExtension::PARAMETER_ROUTES));
        $this->assertSame(
            ConfigurationTest::SECRET_KEY,
            $container->getParameter(LleSaasExtension::PARAMETER_STRIPE_SECRET_KEY)
        );
        $this->assertSame(
            ConfigurationTest::WEBHOOK_SECRET,
            $container->getParameter(LleSaasExtension::PARAMETER_STRIPE_WEBHOOK_SECRET)
        );
        $this->assertSame(
            ConfigurationTest::API_VERSION,
            $container->getParameter(LleSaasExtension::PARAMETER_STRIPE_API_VERSION)
        );
    }

    public function testCredentialWarmupIsRegisteredOnlyWithTheCredentialBundle(): void
    {
        $withoutCredential = new ContainerBuilder();
        $withCredential = $this->containerWithBundle(LleSaasExtension::CREDENTIAL_BUNDLE);
        $extension = new LleSaasExtension();

        $extension->load([ConfigurationTest::minimalConfig()], $withoutCredential);
        $extension->load([ConfigurationTest::minimalConfig()], $withCredential);

        $this->assertFalse($this->isRegistered($withoutCredential, SaasCredentialWarmup::class));
        $this->assertTrue($this->isRegistered($withCredential, SaasCredentialWarmup::class));
        $this->assertTrue($withCredential->getDefinition(SaasCredentialWarmup::class)->isAutoconfigured());
    }

    public function testAdminScreensAreLoadedOnlyWithCrudit(): void
    {
        $withoutCrudit = new ContainerBuilder();
        $withCrudit = $this->containerWithBundle(LleSaasExtension::CRUDIT_BUNDLE);
        $extension = new LleSaasExtension();

        $extension->load([ConfigurationTest::minimalConfig()], $withoutCrudit);
        $extension->load([ConfigurationTest::minimalConfig()], $withCrudit);

        $this->assertFalse($withoutCrudit->getParameter(LleSaasExtension::PARAMETER_CRUDIT_ENABLED));
        $this->assertFalse($withoutCrudit->hasDefinition(PlanCrudConfig::class));
        $this->assertFalse($withoutCrudit->hasDefinition(PlanController::class));
        $this->assertTrue($withCrudit->getParameter(LleSaasExtension::PARAMETER_CRUDIT_ENABLED));
        $this->assertTrue($withCrudit->hasDefinition(PlanCrudConfig::class));
        $this->assertTrue($withCrudit->hasDefinition(SaasMenuProvider::class));
        $this->assertTrue($withCrudit->getDefinition(PlanController::class)->hasTag('controller.service_arguments'));
    }

    public function testTwigFunctionsAreRegisteredOnlyWithTheTwigBundle(): void
    {
        $withoutTwig = new ContainerBuilder();
        $withTwig = $this->containerWithBundle(LleSaasExtension::TWIG_BUNDLE);
        $extension = new LleSaasExtension();

        $extension->load([ConfigurationTest::minimalConfig()], $withoutTwig);
        $extension->load([ConfigurationTest::minimalConfig()], $withTwig);

        $this->assertFalse($this->isRegistered($withoutTwig, SaasTwigExtension::class));
        $this->assertTrue($withTwig->getDefinition(SaasTwigExtension::class)->isAutoconfigured());
    }

    public function testPrependResolvesTargetEntitiesWithDefaultCustomer(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig(LleSaasExtension::ALIAS, ConfigurationTest::minimalConfig());
        $extension = new LleSaasExtension();

        $extension->prepend($container);

        $this->assertSame(
            [
                SaasUserInterface::class => User::class,
                CustomerInterface::class => Customer::class,
            ],
            $this->resolvedTargetEntities($container)
        );
    }

    public function testPrependResolvesOverriddenCustomer(): void
    {
        $container = new ContainerBuilder();
        $config = ConfigurationTest::minimalConfig();
        $config['customer_class'] = self::CUSTOM_CUSTOMER_CLASS;
        $container->prependExtensionConfig(LleSaasExtension::ALIAS, $config);
        $extension = new LleSaasExtension();

        $extension->prepend($container);

        $this->assertSame(
            self::CUSTOM_CUSTOMER_CLASS,
            $this->resolvedTargetEntities($container)[CustomerInterface::class]
        );
    }

    /**
     * A file excluded from the resource of services.yaml is still declared, abstract and tagged "container.excluded".
     */
    protected function isRegistered(ContainerBuilder $container, string $serviceId): bool
    {
        return $container->hasDefinition($serviceId)
            && !$container->getDefinition($serviceId)->hasTag(self::EXCLUDED_TAG);
    }

    protected function containerWithBundle(string $bundle): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(LleSaasExtension::KERNEL_BUNDLES, [$bundle => $bundle]);

        return $container;
    }

    /**
     * @return array<string, string>
     */
    protected function resolvedTargetEntities(ContainerBuilder $container): array
    {
        $resolved = [];
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $resolved = array_merge($resolved, $config['orm']['resolve_target_entities'] ?? []);
        }

        return $resolved;
    }
}
