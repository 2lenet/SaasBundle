<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\DependencyInjection\Compiler;

use Lle\SaasBundle\DependencyInjection\Compiler\ExcludeCustomerMappingPass;
use Lle\SaasBundle\DependencyInjection\LleSaasExtension;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Tests\DependencyInjection\LleSaasExtensionTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ExcludeCustomerMappingPassTest extends TestCase
{
    public const CHAIN_DRIVER_ID = 'doctrine.orm.default_metadata_driver';
    public const BUNDLE_DRIVER_ID = 'doctrine.orm.default_attribute_metadata_driver';
    public const BUNDLE_ENTITY_NAMESPACE = 'Lle\\SaasBundle\\Entity';
    public const EXCLUDE_METHOD = 'addExcludePaths';

    public function testDefaultCustomerKeepsBundleMapping(): void
    {
        $container = $this->container(Customer::class);
        $pass = new ExcludeCustomerMappingPass();

        $pass->process($container);

        $this->assertFalse($container->getDefinition(self::BUNDLE_DRIVER_ID)->hasMethodCall(self::EXCLUDE_METHOD));
    }

    public function testOverriddenCustomerExcludesBundleEntityFromMapping(): void
    {
        $container = $this->container(LleSaasExtensionTest::CUSTOM_CUSTOMER_CLASS);
        $pass = new ExcludeCustomerMappingPass();

        $pass->process($container);

        $customerPath = (new ReflectionClass(Customer::class))->getFileName();
        $this->assertSame(
            [[self::EXCLUDE_METHOD, [[$customerPath]]]],
            $container->getDefinition(self::BUNDLE_DRIVER_ID)->getMethodCalls()
        );
    }

    protected function container(string $customerClass): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(LleSaasExtension::PARAMETER_CUSTOMER_CLASS, $customerClass);
        $container->setDefinition(self::BUNDLE_DRIVER_ID, new Definition());
        $chainDriver = new Definition();
        $chainDriver->addMethodCall('addDriver', [new Reference(self::BUNDLE_DRIVER_ID), self::BUNDLE_ENTITY_NAMESPACE]);
        $container->setDefinition(self::CHAIN_DRIVER_ID, $chainDriver);

        return $container;
    }
}
