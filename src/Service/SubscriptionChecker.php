<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

/**
 * "Does this customer have access to the service / to this plan?" Without customer given, the customer of the
 * connected user. Subscriptions giving access: active, trialing, past_due (see SubscriptionStatus::accessGranting()).
 *
 * Results are kept for the request (one query per customer), cleared between requests (kernel.reset).
 */
class SubscriptionChecker implements ResetInterface
{
    /**
     * Subscriptions giving access, by customer object. A WeakMap: an entry goes with its customer (after a clear()),
     * and is never given to another customer reusing its memory slot.
     *
     * @var WeakMap<CustomerInterface, list<Subscription>>
     */
    protected WeakMap $accessGranting;

    public function __construct(
        protected SubscriptionRepository $subscriptionRepository,
        protected TenantProvider $tenantProvider,
    ) {
        $this->accessGranting = new WeakMap();
    }

    /**
     * Most recent subscription giving access.
     */
    public function getActiveSubscription(?CustomerInterface $customer = null): ?Subscription
    {
        return $this->accessGrantingSubscriptions($customer)[0] ?? null;
    }

    public function hasActiveSubscription(?CustomerInterface $customer = null): bool
    {
        return $this->accessGrantingSubscriptions($customer) !== [];
    }

    public function hasPlan(string $planCode, ?CustomerInterface $customer = null): bool
    {
        foreach ($this->accessGrantingSubscriptions($customer) as $subscription) {
            if ($subscription->getPlan()?->getCode() === $planCode) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        $this->accessGranting = new WeakMap();
    }

    /**
     * @return list<Subscription>
     */
    protected function accessGrantingSubscriptions(?CustomerInterface $customer): array
    {
        $customer ??= $this->tenantProvider->getCurrentCustomer();
        if ($customer === null || $customer->getId() === null) {
            return [];
        }

        return $this->accessGranting[$customer] ??= $this->subscriptionRepository->findAccessGrantingForCustomer($customer);
    }
}
