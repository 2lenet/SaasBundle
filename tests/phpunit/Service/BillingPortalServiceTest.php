<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Exception\NoStripeCustomerException;
use Lle\SaasBundle\Service\BillingPortalService;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use PHPUnit\Framework\TestCase;
use Stripe\BillingPortal\Session;

class BillingPortalServiceTest extends TestCase
{
    public const STRIPE_CUSTOMER_ID = 'cus_portal';
    public const RETURN_URL = 'https://app.test/account';
    public const PORTAL_URL = 'https://billing.stripe.com/p/session/bps_1';

    public function testOpensThePortalOfTheStripeCustomer(): void
    {
        $customer = new Customer();
        $customer->setStripeCustomerId(self::STRIPE_CUSTOMER_ID);
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->once())
            ->method('createBillingPortalSession')
            ->with(['customer' => self::STRIPE_CUSTOMER_ID, 'return_url' => self::RETURN_URL])
            ->willReturn(Session::constructFrom(['id' => 'bps_1', 'url' => self::PORTAL_URL]));
        $service = new BillingPortalService($gateway);

        $session = $service->createSession($customer, self::RETURN_URL);

        $this->assertSame(self::PORTAL_URL, $session->url);
    }

    public function testCustomerUnknownToStripeHasNoPortal(): void
    {
        $gateway = $this->createMock(StripeGatewayInterface::class);
        $gateway->expects($this->never())->method('createBillingPortalSession');
        $service = new BillingPortalService($gateway);
        $customer = new Customer();

        $this->expectException(NoStripeCustomerException::class);

        $service->createSession($customer, self::RETURN_URL);
    }
}
