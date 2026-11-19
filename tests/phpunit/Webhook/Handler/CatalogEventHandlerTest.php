<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Webhook\Handler;

use Lle\SaasBundle\Dto\PlanSyncReport;
use Lle\SaasBundle\Service\PlanSynchronizer;
use Lle\SaasBundle\Tests\Fixtures\Stripe\StripeEventPayload;
use Lle\SaasBundle\Webhook\Handler\CatalogEventHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stripe\Event;

class CatalogEventHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function eventTypes(): iterable
    {
        yield 'product updated' => ['product.updated', true];
        yield 'product deleted' => ['product.deleted', true];
        yield 'price created' => ['price.created', true];
        yield 'price updated' => ['price.updated', true];
        yield 'invoice' => ['invoice.paid', false];
    }

    #[DataProvider('eventTypes')]
    public function testSupports(string $eventType, bool $expected): void
    {
        $handler = new CatalogEventHandler($this->createStub(PlanSynchronizer::class));

        $this->assertSame($expected, $handler->supports($eventType));
    }

    public function testResynchronizesThePlans(): void
    {
        $synchronizer = $this->createMock(PlanSynchronizer::class);
        $synchronizer->expects($this->once())->method('synchronize')->willReturn(new PlanSyncReport());
        $handler = new CatalogEventHandler($synchronizer);

        $handler->handle(Event::constructFrom(StripeEventPayload::event('evt_price', 'price.updated', ['id' => 'price_pro', 'object' => 'price'])));
    }
}
