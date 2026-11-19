<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Controller\BillingController;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Security\SaasRole;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use ReflectionMethod;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class BillingControllerTest extends WebTestCase
{
    public const CHECKOUT_URL = '/saas/checkout/pro';
    public const PORTAL_URL = '/saas/billing-portal';
    public const STRIPE_CHECKOUT_URL = 'https://checkout.stripe.com/c/pay/cs_test_1';
    public const STRIPE_PORTAL_URL = 'https://billing.stripe.com/p/session/bps_test_1';
    public const STRIPE_CUSTOMER_ID = 'cus_web';

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected FakeStripeHttpClient $stripe;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
        DatabaseTestCase::resetSchema($entityManager);

        $this->stripe = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function testOwnerIsRedirectedToStripeCheckout(): void
    {
        $this->givenPlan(true);
        $this->login(CustomerRole::Owner, [SaasRole::CUSTOMER_ACTION_CHECKOUT], null);
        $this->stripe->queue(['id' => self::STRIPE_CUSTOMER_ID, 'object' => 'customer']);
        $this->stripe->queue(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => self::STRIPE_CHECKOUT_URL]);

        $this->client->request('POST', self::CHECKOUT_URL);

        $this->assertResponseRedirects(self::STRIPE_CHECKOUT_URL, Response::HTTP_SEE_OTHER);
        $checkoutParams = $this->stripe->getRequests()[1]['params'];
        $this->assertSame('http://localhost/billing/success?session_id={CHECKOUT_SESSION_ID}', $checkoutParams['success_url']);
        $this->assertSame('http://localhost/pricing', $checkoutParams['cancel_url']);
        $this->assertCount(2, $this->entityManager->getRepository(StripeApiCall::class)->findAll());
    }

    public function testCheckoutRequiresTheCredentialRole(): void
    {
        $this->givenPlan(true);
        $this->login(CustomerRole::Owner, [], self::STRIPE_CUSTOMER_ID);

        $this->client->request('POST', self::CHECKOUT_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertSame([], $this->stripe->getRequests());
    }

    public function testCheckoutIsReservedToTheOwner(): void
    {
        $this->givenPlan(true);
        $this->login(CustomerRole::Member, [SaasRole::CUSTOMER_ACTION_CHECKOUT], self::STRIPE_CUSTOMER_ID);

        $this->client->request('POST', self::CHECKOUT_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUnknownOrInactivePlanIsNotFound(): void
    {
        $this->givenPlan(false);
        $this->login(CustomerRole::Owner, [SaasRole::CUSTOMER_ACTION_CHECKOUT], self::STRIPE_CUSTOMER_ID);

        $this->client->request('POST', '/saas/checkout/unknown');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request('POST', self::CHECKOUT_URL);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testCheckoutOnlyAcceptsPost(): void
    {
        $this->givenPlan(true);
        $this->login(CustomerRole::Owner, [SaasRole::CUSTOMER_ACTION_CHECKOUT], self::STRIPE_CUSTOMER_ID);

        $this->client->request('GET', self::CHECKOUT_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testOwnerIsRedirectedToTheBillingPortal(): void
    {
        $this->login(CustomerRole::Owner, [SaasRole::CUSTOMER_ACTION_BILLINGPORTAL], self::STRIPE_CUSTOMER_ID);
        $this->stripe->queue(['id' => 'bps_test_1', 'object' => 'billing_portal.session', 'url' => self::STRIPE_PORTAL_URL]);

        $this->client->request('POST', self::PORTAL_URL);

        $this->assertResponseRedirects(self::STRIPE_PORTAL_URL, Response::HTTP_SEE_OTHER);
        $this->assertSame('http://localhost/account', $this->stripe->getRequests()[0]['params']['return_url']);
    }

    public function testBillingPortalNeedsAStripeCustomer(): void
    {
        $this->login(CustomerRole::Owner, [SaasRole::CUSTOMER_ACTION_BILLINGPORTAL], null);

        $this->client->request('POST', self::PORTAL_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnonymousIsRejected(): void
    {
        $this->client->request('POST', self::PORTAL_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * CSRF is disabled in the test kernel: check that both actions keep their token check.
     */
    public function testActionsAreProtectedAgainstCsrf(): void
    {
        foreach ([BillingController::CSRF_CHECKOUT => 'checkout', BillingController::CSRF_BILLING_PORTAL => 'billingPortal'] as $tokenId => $method) {
            $attributes = (new ReflectionMethod(BillingController::class, $method))->getAttributes(IsCsrfTokenValid::class);

            $this->assertCount(1, $attributes);
            $this->assertSame($tokenId, $attributes[0]->newInstance()->id);
        }
    }

    protected function givenPlan(bool $active): void
    {
        $plan = new Plan();
        $plan->setCode('pro')->setLabel('Pro')->setStripeProductId('prod_pro')->setStripePriceId('price_pro')->setActive($active);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $roles
     */
    protected function login(CustomerRole $customerRole, array $roles, ?string $stripeCustomerId): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId($stripeCustomerId);
        $user = new User(CustomerTest::USER_EMAIL);
        $user->setRoles($roles)->setCustomerRole($customerRole);
        $customer->addUser($user);
        $this->entityManager->persist($customer);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
    }
}
