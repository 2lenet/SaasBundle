<?php

declare(strict_types=1);

namespace Lle\SaasBundle\DependencyInjection;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Security\SaasCredentialWarmup;
use Lle\SaasBundle\Twig\SaasTwigExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class LleSaasExtension extends Extension implements PrependExtensionInterface
{
    public const ALIAS = 'lle_saas';
    public const DOCTRINE_ALIAS = 'doctrine';

    public const PARAMETER_USER_CLASS = 'lle_saas.user_class';
    public const PARAMETER_CUSTOMER_CLASS = 'lle_saas.customer_class';
    public const PARAMETER_ROUTES = 'lle_saas.routes';
    public const PARAMETER_STRIPE_SECRET_KEY = 'lle_saas.stripe.secret_key';
    public const PARAMETER_STRIPE_WEBHOOK_SECRET = 'lle_saas.stripe.webhook_secret';
    public const PARAMETER_STRIPE_API_VERSION = 'lle_saas.stripe.api_version';
    public const PARAMETER_STRIPE_APP_ID = 'lle_saas.stripe.app_id';
    public const PARAMETER_CRUDIT_ENABLED = 'lle_saas.crudit.enabled';
    /** Optional bundles: their integration is registered only when the application enables them. */
    public const CRUDIT_BUNDLE = 'LleCruditBundle';
    public const CREDENTIAL_BUNDLE = 'LleCredentialBundle';
    public const TWIG_BUNDLE = 'TwigBundle';
    public const KERNEL_BUNDLES = 'kernel.bundles';

    /**
     * Binds the bundle interfaces to the application entities, so that relations can target the interfaces.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $userClass = null;
        $customerClass = Customer::class;
        foreach ($container->getExtensionConfig(self::ALIAS) as $config) {
            $userClass = $config['user_class'] ?? $userClass;
            $customerClass = $config['customer_class'] ?? $customerClass;
        }

        $resolveTargetEntities = [];
        if (is_string($userClass)) {
            $resolveTargetEntities[SaasUserInterface::class] = $userClass;
        }
        $resolveTargetEntities[CustomerInterface::class] = $customerClass;

        $container->prependExtensionConfig(self::DOCTRINE_ALIAS, [
            'orm' => [
                'resolve_target_entities' => $resolveTargetEntities,
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter(self::PARAMETER_USER_CLASS, $config['user_class']);
        $container->setParameter(self::PARAMETER_CUSTOMER_CLASS, $config['customer_class']);
        $container->setParameter(self::PARAMETER_ROUTES, $config['routes']);
        $container->setParameter(self::PARAMETER_STRIPE_SECRET_KEY, $config['stripe']['secret_key']);
        $container->setParameter(self::PARAMETER_STRIPE_WEBHOOK_SECRET, $config['stripe']['webhook_secret']);
        $container->setParameter(self::PARAMETER_STRIPE_API_VERSION, $config['stripe']['api_version']);
        $container->setParameter(self::PARAMETER_STRIPE_APP_ID, $config['stripe']['app_id']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // roles declared to the credential bundle: tagged "credential.warmup" through its autoconfiguration
        if (self::hasBundle($container, self::CREDENTIAL_BUNDLE)) {
            $container->register(SaasCredentialWarmup::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }

        // admin screens (optional dependency, as in PdfGeneratorBundle)
        $crudit = self::hasBundle($container, self::CRUDIT_BUNDLE);
        $container->setParameter(self::PARAMETER_CRUDIT_ENABLED, $crudit);
        if ($crudit) {
            $loader->load('services_crudit.yaml');
        }

        // Twig functions saas_has_subscription(), saas_has_plan(), saas_subscription()
        if (self::hasBundle($container, self::TWIG_BUNDLE)) {
            $container->register(SaasTwigExtension::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }
    }

    /**
     * An optional integration depends on the bundle being enabled, not only installed: its services (tags, routes,
     * templates) come with the bundle.
     */
    public static function hasBundle(ContainerBuilder $container, string $bundle): bool
    {
        $bundles = $container->hasParameter(self::KERNEL_BUNDLES) ? $container->getParameter(self::KERNEL_BUNDLES) : [];

        return is_array($bundles) && isset($bundles[$bundle]);
    }

    public function getAlias(): string
    {
        return self::ALIAS;
    }
}
