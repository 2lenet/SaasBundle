<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Twig;

use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Service\SubscriptionChecker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Subscription of the customer of the connected user, in the templates. Registered only when Twig is installed.
 *
 *     {% if saas_has_subscription() %} ... {% endif %}
 *     {% if saas_has_plan('pro') %} ... {% endif %}
 *     {{ saas_subscription().plan.label ?? '' }}
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class SaasTwigExtension extends AbstractExtension
{
    public const FUNCTION_SUBSCRIPTION = 'saas_subscription';
    public const FUNCTION_HAS_SUBSCRIPTION = 'saas_has_subscription';
    public const FUNCTION_HAS_PLAN = 'saas_has_plan';

    public function __construct(
        protected SubscriptionChecker $subscriptionChecker,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(self::FUNCTION_SUBSCRIPTION, $this->subscription(...)),
            new TwigFunction(self::FUNCTION_HAS_SUBSCRIPTION, $this->hasSubscription(...)),
            new TwigFunction(self::FUNCTION_HAS_PLAN, $this->hasPlan(...)),
        ];
    }

    public function subscription(): ?Subscription
    {
        return $this->subscriptionChecker->getActiveSubscription();
    }

    public function hasSubscription(): bool
    {
        return $this->subscriptionChecker->hasActiveSubscription();
    }

    public function hasPlan(string $planCode): bool
    {
        return $this->subscriptionChecker->hasPlan($planCode);
    }
}
