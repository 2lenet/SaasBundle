<?php

declare(strict_types=1);

namespace Lle\SaasBundle\DependencyInjection;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Lle\SaasBundle\Controller\BillingController;
use Lle\SaasBundle\Entity\Customer;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /** Stripe metadata values are limited to 500 characters; kept short and readable. */
    public const APP_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(LleSaasExtension::ALIAS);

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('user_class')
                    ->info('User entity of the application, implementing ' . SaasUserInterface::class)
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $class): bool => !self::implements($class, SaasUserInterface::class))
                        ->thenInvalid('%s must implement ' . SaasUserInterface::class)
                    ->end()
                ->end()
                ->scalarNode('customer_class')
                    ->info('Customer entity, implementing ' . CustomerInterface::class)
                    ->defaultValue(Customer::class)
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(static fn (mixed $class): bool => !self::implements($class, CustomerInterface::class))
                        ->thenInvalid('%s must implement ' . CustomerInterface::class)
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->info('Application routes the customer is sent back to from the Stripe hosted pages')
                    ->isRequired()
                    ->children()
                        ->scalarNode(BillingController::REDIRECT_CHECKOUT_SUCCESS)
                            ->info('After a successful checkout (receives a "session_id" query parameter)')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode(BillingController::REDIRECT_CHECKOUT_CANCEL)
                            ->info('When the customer leaves the checkout without paying')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode(BillingController::REDIRECT_BILLING_PORTAL_RETURN)
                            ->info('When the customer leaves the billing portal')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('stripe')
                    ->isRequired()
                    ->children()
                        ->scalarNode('secret_key')
                            ->info('Stripe secret API key, e.g. "%env(STRIPE_SECRET_KEY)%"')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('webhook_secret')
                            ->info('Signing secret of the Stripe webhook endpoint, e.g. "%env(STRIPE_WEBHOOK_SECRET)%"')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('app_id')
                            ->info(
                                'Id of the application in the Stripe account (metadata "lle_saas_app"), e.g. "rgaa_checker": '
                                . 'the account may be shared by several applications'
                            )
                            ->isRequired()
                            ->cannotBeEmpty()
                            ->validate()
                                ->ifTrue(static fn (mixed $appId): bool => !is_string($appId) || preg_match(self::APP_ID_PATTERN, $appId) !== 1)
                                ->thenInvalid('%s: letters, digits, "_" or "-", 40 characters at most')
                            ->end()
                        ->end()
                        ->scalarNode('api_version')
                            ->info('Stripe API version to pin; null uses the version of the SDK')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    protected static function implements(mixed $class, string $interface): bool
    {
        return is_string($class) && is_a($class, $interface, true);
    }
}
