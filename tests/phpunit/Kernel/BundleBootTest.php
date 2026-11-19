<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Kernel;

use Lle\SaasBundle\Command\MonitorPurgeCommand;
use Lle\SaasBundle\Command\PlanSyncCommand;
use Lle\SaasBundle\Command\ReconcileCommand;
use Lle\SaasBundle\Command\WebhookReplayCommand;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\DependencyInjection\LleSaasExtension;
use Lle\SaasBundle\Stripe\StripeGateway;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class BundleBootTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testBundleBootsWithItsConfiguration(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->assertSame(
            'sk_test_kernel',
            $container->getParameter(LleSaasExtension::PARAMETER_STRIPE_SECRET_KEY)
        );
    }

    public function testStripeGatewayIsWired(): void
    {
        self::bootKernel();

        $gateway = static::getContainer()->get(StripeGatewayInterface::class);

        $this->assertInstanceOf(StripeGateway::class, $gateway);
    }

    public function testPlanSyncCommandIsRegistered(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has(PlanSyncCommand::NAME));
        $this->assertTrue($application->has(WebhookReplayCommand::NAME));
        $this->assertTrue($application->has(ReconcileCommand::NAME));
        $this->assertTrue($application->has(MonitorPurgeCommand::NAME));
    }

    public function testTranslationsOfTheBundleAreLoaded(): void
    {
        self::bootKernel();
        /** @var TranslatorInterface $translator */
        $translator = static::getContainer()->get(TranslatorInterface::class);

        $this->assertSame('Actif', $translator->trans('enum.active', [], AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN, 'fr'));
        $this->assertSame('Active', $translator->trans('enum.active', [], AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN, 'en'));
    }
}
