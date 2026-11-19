<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Controller\BillingController;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Enum\CustomerRole;
use Lle\SaasBundle\Security\SaasRole;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Entity\User;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

/**
 * The billing actions in a kernel where the CSRF protection is really active.
 */
class BillingControllerCsrfTest extends WebTestCase
{
    protected KernelBrowser $client;
    protected FakeStripeHttpClient $stripe;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => TestKernel::ENVIRONMENT_CSRF]);
        $this->client->disableReboot();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        DatabaseTestCase::resetSchema($entityManager);

        $plan = new Plan();
        $plan->setCode('pro')->setLabel('Pro')->setStripeProductId('prod_pro')->setStripePriceId('price_pro');
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId('cus_csrf');
        $user = new User(CustomerTest::USER_EMAIL);
        $user->setRoles([SaasRole::CUSTOMER_ACTION_CHECKOUT, SaasRole::CUSTOMER_ACTION_BILLINGPORTAL])
            ->setCustomerRole(CustomerRole::Owner);
        $customer->addUser($user);
        $entityManager->persist($plan);
        $entityManager->persist($customer);
        $entityManager->persist($user);
        $entityManager->flush();
        $this->client->loginUser($user);

        $this->stripe = new FakeStripeHttpClient();
        ApiRequestor::setHttpClient($this->stripe);
    }

    public function testValidTokenOpensTheBillingPortal(): void
    {
        $token = $this->storeCsrfToken(BillingController::CSRF_BILLING_PORTAL);
        $this->stripe->queue(['id' => 'bps_csrf', 'object' => 'billing_portal.session', 'url' => BillingControllerTest::STRIPE_PORTAL_URL]);

        $this->client->request('POST', BillingControllerTest::PORTAL_URL, ['_token' => $token]);

        $this->assertResponseRedirects(BillingControllerTest::STRIPE_PORTAL_URL, Response::HTTP_SEE_OTHER);
        $this->assertCount(1, $this->stripe->getRequests());
    }

    /**
     * Puts a token in the session opened by loginUser(), like a form rendered with csrf_token() would.
     */
    protected function storeCsrfToken(string $tokenId): string
    {
        /** @var SessionFactoryInterface $sessionFactory */
        $sessionFactory = static::getContainer()->get('session.factory');
        $session = $sessionFactory->createSession();
        $session->setId((string) $this->client->getCookieJar()->get($session->getName())?->getValue());
        $session->start();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set(SessionTokenStorage::SESSION_NAMESPACE . '/' . $tokenId, $token);
        $session->save();

        return $token;
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function forgedRequests(): iterable
    {
        yield 'checkout without token' => [BillingControllerTest::CHECKOUT_URL, []];
        yield 'checkout with a wrong token' => [BillingControllerTest::CHECKOUT_URL, ['_token' => 'forged']];
        yield 'billing portal without token' => [BillingControllerTest::PORTAL_URL, []];
        yield 'billing portal with a wrong token' => [BillingControllerTest::PORTAL_URL, ['_token' => 'forged']];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('forgedRequests')]
    public function testForgedRequestIsRejectedBeforeCallingStripe(string $url, array $parameters): void
    {
        $this->client->request('POST', $url, $parameters);

        // InvalidCsrfTokenException is an authentication exception: the firewall answers 401 (or its entry point)
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->assertSame([], $this->stripe->getRequests());
    }
}
