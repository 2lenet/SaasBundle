<?php

declare(strict_types=1);

namespace Lle\SaasBundle\DependencyInjection\Compiler;

use Lle\SaasBundle\DependencyInjection\LleSaasExtension;
use Lle\SaasBundle\Entity\Customer;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * When the application brings its own customer entity, removes the default Customer from the Doctrine mapping
 * so that only one entity is bound to the customer (same approach as HermesBundle's ExcludeTemplateMappingPass).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class ExcludeCustomerMappingPass implements CompilerPassInterface
{
    public const METADATA_DRIVER_SUFFIX = '_metadata_driver';
    public const ADD_DRIVER_METHOD = 'addDriver';
    public const EXCLUDE_PATHS_METHOD = 'addExcludePaths';
    public const ENTITY_NAMESPACE = 'Lle\\SaasBundle\\Entity';

    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasParameter(LleSaasExtension::PARAMETER_CUSTOMER_CLASS)
            || $container->getParameter(LleSaasExtension::PARAMETER_CUSTOMER_CLASS) === Customer::class
        ) {
            return;
        }

        $customerPath = (new ReflectionClass(Customer::class))->getFileName();
        if ($customerPath === false) {
            return;
        }

        foreach ($container->getDefinitions() as $id => $chainDriver) {
            if (!str_ends_with($id, self::METADATA_DRIVER_SUFFIX)) {
                continue;
            }

            foreach ($chainDriver->getMethodCalls() as [$method, $arguments]) {
                if ($method !== self::ADD_DRIVER_METHOD || ($arguments[1] ?? null) !== self::ENTITY_NAMESPACE) {
                    continue;
                }

                $driver = $arguments[0];
                if ($driver instanceof Reference) {
                    $container->getDefinition((string) $driver)
                        ->addMethodCall(self::EXCLUDE_PATHS_METHOD, [[$customerPath]]);
                }
            }
        }
    }
}
