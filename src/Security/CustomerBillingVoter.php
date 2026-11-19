<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Security;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Only the owner of a customer account manages its billing. Without subject, checks that the user owns a customer;
 * with a customer as subject, checks that the user owns this one.
 *
 * Business rule, complementary to the ROLE_SAAS_* roles of the credential bundle (not a role itself).
 *
 * @extends Voter<string, CustomerInterface|null>
 */
class CustomerBillingVoter extends Voter
{
    public const MANAGE_BILLING = 'LLE_SAAS_MANAGE_BILLING';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGE_BILLING && ($subject === null || $subject instanceof CustomerInterface);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof SaasUserInterface || !$user->isCustomerOwner()) {
            return false;
        }

        $customer = $user->getCustomer();
        if ($customer === null) {
            return false;
        }

        return $subject === null || $subject === $customer;
    }
}
