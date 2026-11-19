<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Security;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Security\SubscriptionVoter;
use Lle\SaasBundle\Service\SubscriptionChecker;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class SubscriptionVoterTest extends TestCase
{
    public const PLAN_CODE = 'pro';

    public function testAbstainsOnOtherAttributes(): void
    {
        $voter = new SubscriptionVoter($this->createStub(SubscriptionChecker::class));

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), null, ['ROLE_ADMIN']));
    }

    public function testGrantsACustomerWithAnActiveSubscription(): void
    {
        $customer = new Customer();
        $checker = $this->createMock(SubscriptionChecker::class);
        $checker->expects($this->once())->method('hasActiveSubscription')->with($customer)->willReturn(true);
        $voter = new SubscriptionVoter($checker);

        $vote = $voter->vote($this->token($customer), null, [SubscriptionVoter::SUBSCRIBED]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $vote);
    }

    public function testChecksThePlanGivenAsSubject(): void
    {
        $customer = new Customer();
        $checker = $this->createMock(SubscriptionChecker::class);
        $checker->expects($this->once())->method('hasPlan')->with(self::PLAN_CODE, $customer)->willReturn(false);
        $voter = new SubscriptionVoter($checker);

        $vote = $voter->vote($this->token($customer), self::PLAN_CODE, [SubscriptionVoter::SUBSCRIBED]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testDeniesAnotherCustomer(): void
    {
        $checker = $this->createStub(SubscriptionChecker::class);
        $checker->method('hasActiveSubscription')->willReturn(true);
        $voter = new SubscriptionVoter($checker);

        $vote = $voter->vote($this->token(new Customer()), new Customer(), [SubscriptionVoter::SUBSCRIBED]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testDeniesAnonymous(): void
    {
        $voter = new SubscriptionVoter($this->createStub(SubscriptionChecker::class));

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [SubscriptionVoter::SUBSCRIBED]));
    }

    protected function token(Customer $customer): UsernamePasswordToken
    {
        $user = new User(CustomerTest::USER_EMAIL);
        $user->setCustomerRole(CustomerRole::Member);
        $customer->addUser($user);

        return new UsernamePasswordToken($user, 'main');
    }
}
