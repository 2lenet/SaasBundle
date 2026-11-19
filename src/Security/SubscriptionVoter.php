<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Security;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Lle\SaasBundle\Service\SubscriptionChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Access to the paid service:
 *  - no subject: the customer of the user has a subscription giving access;
 *  - a plan code: ... to this plan;
 *  - a customer: this customer (which must be the one of the user) has a subscription giving access.
 *
 * Business rule, complementary to the roles of the credential bundle (not a role itself).
 *
 * @extends Voter<string, CustomerInterface|string|null>
 */
class SubscriptionVoter extends Voter
{
    public const SUBSCRIBED = 'LLE_SAAS_SUBSCRIBED';

    public function __construct(
        protected SubscriptionChecker $subscriptionChecker,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SUBSCRIBED
            && ($subject === null || is_string($subject) || $subject instanceof CustomerInterface);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $customer = $user instanceof SaasUserInterface ? $user->getCustomer() : null;
        if ($customer === null) {
            return false;
        }

        if (is_string($subject)) {
            return $this->subscriptionChecker->hasPlan($subject, $customer);
        }

        return ($subject === null || $subject === $customer) && $this->subscriptionChecker->hasActiveSubscription($customer);
    }
}
