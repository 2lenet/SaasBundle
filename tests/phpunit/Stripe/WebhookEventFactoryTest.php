<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Stripe;

use Lle\SaasBundle\Exception\InvalidWebhookException;
use Lle\SaasBundle\Stripe\WebhookEventFactory;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use PHPUnit\Framework\TestCase;

class WebhookEventFactoryTest extends TestCase
{
    public const SECRET = 'whsec_factory';
    public const EVENT_ID = 'evt_factory';
    public const EVENT_TYPE = 'customer.updated';

    public function testBuildsTheEventWhenTheSignatureIsValid(): void
    {
        $payload = $this->payload();
        $factory = new WebhookEventFactory(self::SECRET);

        $event = $factory->create($payload, StripeEventPayload::sign($payload, self::SECRET));

        $this->assertSame(self::EVENT_ID, $event->id);
        $this->assertSame(self::EVENT_TYPE, $event->type);
    }

    public function testRejectsASignatureMadeWithAnotherSecret(): void
    {
        $payload = $this->payload();
        $signature = StripeEventPayload::sign($payload, 'whsec_other');
        $factory = new WebhookEventFactory(self::SECRET);

        $this->expectException(InvalidWebhookException::class);

        $factory->create($payload, $signature);
    }

    public function testRejectsAMissingSignature(): void
    {
        $factory = new WebhookEventFactory(self::SECRET);

        $this->expectException(InvalidWebhookException::class);

        $factory->create($this->payload(), '');
    }

    public function testRejectsAPayloadThatIsNotJson(): void
    {
        $payload = 'not json';
        $signature = StripeEventPayload::sign($payload, self::SECRET);
        $factory = new WebhookEventFactory(self::SECRET);

        $this->expectException(InvalidWebhookException::class);

        $factory->create($payload, $signature);
    }

    protected function payload(): string
    {
        return StripeEventPayload::json(self::EVENT_ID, self::EVENT_TYPE, ['id' => 'cus_1', 'object' => 'customer']);
    }
}
