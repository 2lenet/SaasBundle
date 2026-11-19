<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Controller\WebhookController;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Tests\Fixtures\Webhook\RecordingStripeEventHandler;
use Lle\SaasBundle\Tests\Kernel\TestKernel;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class WebhookControllerTest extends WebTestCase
{
    public const WEBHOOK_URL = '/saas/webhook';

    protected KernelBrowser $client;

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
        DatabaseTestCase::resetSchema($entityManager);
    }

    public function testSignedEventIsAcknowledgedWithoutAuthentication(): void
    {
        $payload = StripeEventPayload::json('evt_web', 'invoice.created', ['id' => 'in_1', 'object' => 'invoice']);

        $this->post($payload, StripeEventPayload::sign($payload, TestKernel::WEBHOOK_SECRET));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['status' => 'ignored'], $this->responseData());
    }

    public function testWrongSignatureIsRejected(): void
    {
        $payload = StripeEventPayload::json('evt_web', 'invoice.created', ['id' => 'in_1', 'object' => 'invoice']);

        $this->post($payload, StripeEventPayload::sign($payload, 'whsec_other'));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        // nothing about the expected secret (the SDK message gives its length and last characters)
        $this->assertSame([WebhookController::KEY_ERROR => WebhookController::ERROR_INVALID_SIGNATURE], $this->responseData());
    }

    public function testFailedProcessingAsksStripeToRetry(): void
    {
        /** @var RecordingStripeEventHandler $handler */
        $handler = static::getContainer()->get(RecordingStripeEventHandler::class);
        $handler->failing = true;
        $payload = StripeEventPayload::json('evt_web', RecordingStripeEventHandler::TYPE, ['id' => 'obj_1', 'object' => 'test']);

        $this->post($payload, StripeEventPayload::sign($payload, TestKernel::WEBHOOK_SECRET));

        $this->assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertSame(['status' => 'failed'], $this->responseData());
    }

    public function testDeliveryWhileAnotherOneIsProcessedAsksStripeToRetry(): void
    {
        $payload = StripeEventPayload::json('evt_web', RecordingStripeEventHandler::TYPE, ['id' => 'obj_1', 'object' => 'test']);
        /** @var RecordingStripeEventHandler $handler */
        $handler = static::getContainer()->get(RecordingStripeEventHandler::class);
        $handler->failing = true;
        $this->post($payload, StripeEventPayload::sign($payload, TestKernel::WEBHOOK_SECRET));
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            "UPDATE lle_saas_stripe_event SET status = 'processing', claimed_at = ? WHERE stripe_event_id = 'evt_web'",
            [(new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );

        $this->post($payload, StripeEventPayload::sign($payload, TestKernel::WEBHOOK_SECRET));

        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->assertSame(['status' => 'processing'], $this->responseData());
    }

    public function testOnlyPostIsAccepted(): void
    {
        $this->client->request('GET', self::WEBHOOK_URL);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    protected function post(string $payload, string $signature): void
    {
        $this->client->request('POST', self::WEBHOOK_URL, [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $data;
    }
}
