<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Entity\Customer;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Event\SubscriptionActivatedEvent;
use Lle\SaasBundle\Tests\Entity\CustomerTest;
use Lle\SaasBundle\Tests\Fixtures\Stripe\FakeStripeHttpClient;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Lle\SaasBundle\Webhook\StripeEventType;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A signed Stripe delivery through the whole chain: controller, processor, subscription handler, synchronizer,
 * event of the application.
 */
class WebhookSubscriptionFlowTest extends WebTestCase
{
    public const EVENT_ID = 'evt_flow';
    public const PLAN_CODE = 'pro';

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

    public function testCreatedSubscriptionGivesAccessAndNotifiesTheApplication(): void
    {
        $customer = new Customer();
        $customer->setName(CustomerTest::CUSTOMER_NAME)->setStripeCustomerId(StripeEventPayload::STRIPE_CUSTOMER_ID);
        $plan = new Plan();
        $plan->setCode(self::PLAN_CODE)->setLabel('Pro')->setStripeProductId('prod_pro')->setStripePriceId(StripeEventPayload::STRIPE_PRICE_ID);
        $this->entityManager->persist($customer);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();
        $activated = [];
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(SubscriptionActivatedEvent::class, static function (SubscriptionActivatedEvent $event) use (&$activated): void {
            $activated[] = $event->getSubscription()->getStripeSubscriptionId();
        });
        // the handler reads the subscription again from Stripe
        $this->stripe->queue(StripeEventPayload::subscription());
        $payload = StripeEventPayload::json(self::EVENT_ID, StripeEventType::CUSTOMER_SUBSCRIPTION_CREATED, StripeEventPayload::subscription());

        $this->client->request('POST', WebhookControllerTest::WEBHOOK_URL, [], [], [
            'HTTP_STRIPE_SIGNATURE' => StripeEventPayload::sign($payload, TestKernel::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertResponseIsSuccessful();
        $this->assertSame([StripeEventPayload::SUBSCRIPTION_ID], $activated);
        $this->entityManager->clear();
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['stripeSubscriptionId' => StripeEventPayload::SUBSCRIPTION_ID]);
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Active, $subscription->getStatus());
        $this->assertSame(self::PLAN_CODE, $subscription->getPlan()?->getCode());
        $this->assertSame(StripeEventPayload::QUANTITY, $subscription->getQuantity());
        $this->assertSame(CustomerTest::CUSTOMER_NAME, $subscription->getCustomer()?->getName());
        $journal = $this->entityManager->getRepository(StripeEvent::class)->findOneBy(['stripeEventId' => self::EVENT_ID]);
        $this->assertSame(StripeEventStatus::Processed, $journal?->getStatus());
    }
}
