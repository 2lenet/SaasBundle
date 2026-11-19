<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Twig;

use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Service\SubscriptionChecker;
use Lle\SaasBundle\Twig\SaasTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class SaasTwigExtensionTest extends TestCase
{
    public function testFunctionsAnswerForTheConnectedCustomer(): void
    {
        $subscription = (new Subscription())->setStripeSubscriptionId('sub_twig');
        $checker = $this->createStub(SubscriptionChecker::class);
        $checker->method('hasActiveSubscription')->willReturn(true);
        $checker->method('getActiveSubscription')->willReturn($subscription);
        $checker->method('hasPlan')->willReturnCallback(static fn (string $planCode): bool => $planCode === 'pro');
        $twig = new Environment(new ArrayLoader([
            'page' => "{{ saas_has_subscription() ? 'yes' : 'no' }}|{{ saas_has_plan('pro') ? 'yes' : 'no' }}"
                . "|{{ saas_has_plan('starter') ? 'yes' : 'no' }}|{{ saas_subscription().stripeSubscriptionId }}",
        ]));
        $twig->addExtension(new SaasTwigExtension($checker));

        $html = $twig->render('page');

        $this->assertSame('yes|yes|no|sub_twig', $html);
    }
}
