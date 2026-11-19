<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Security;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Security\CustomerBillingVoter;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class CustomerBillingVoterTest extends TestCase
{
    public function testAbstainsOnOtherAttributes(): void
    {
        $voter = new CustomerBillingVoter();
        $token = $this->token($this->user(CustomerRole::Owner, new Customer()));

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['ROLE_ADMIN']));
    }

    public function testGrantsTheOwnerOfACustomer(): void
    {
        $voter = new CustomerBillingVoter();
        $token = $this->token($this->user(CustomerRole::Owner, new Customer()));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [CustomerBillingVoter::MANAGE_BILLING]));
    }

    public function testGrantsTheOwnerOnItsOwnCustomerOnly(): void
    {
        $voter = new CustomerBillingVoter();
        $customer = new Customer();
        $token = $this->token($this->user(CustomerRole::Owner, $customer));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $customer, [CustomerBillingVoter::MANAGE_BILLING]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, new Customer(), [CustomerBillingVoter::MANAGE_BILLING]));
    }

    public function testDeniesAMember(): void
    {
        $voter = new CustomerBillingVoter();
        $token = $this->token($this->user(CustomerRole::Member, new Customer()));

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [CustomerBillingVoter::MANAGE_BILLING]));
    }

    public function testDeniesAnOwnerWithoutCustomer(): void
    {
        $voter = new CustomerBillingVoter();
        $token = $this->token($this->user(CustomerRole::Owner, null));

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [CustomerBillingVoter::MANAGE_BILLING]));
    }

    public function testDeniesAnonymous(): void
    {
        $voter = new CustomerBillingVoter();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [CustomerBillingVoter::MANAGE_BILLING]));
    }

    protected function user(CustomerRole $role, ?Customer $customer): User
    {
        $user = new User(CustomerTest::USER_EMAIL);
        $user->setCustomerRole($role)->setCustomer($customer);

        return $user;
    }

    protected function token(User $user): TokenInterface
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
