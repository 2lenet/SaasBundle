<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use ArrayObject;
use Lle\SaasBundle\Service\LifecycleEventDispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

class LifecycleEventDispatcherTest extends TestCase
{
    public function testDispatchesAtOnceOutsideAWebhook(): void
    {
        $received = new ArrayObject();
        $dispatcher = $this->dispatcher($received);

        $dispatcher->dispatch(new ArrayObject(['first']));

        $this->assertCount(1, $received);
    }

    public function testKeepsTheEventsUntilReleased(): void
    {
        $received = new ArrayObject();
        $dispatcher = $this->dispatcher($received);
        $dispatcher->defer();
        $dispatcher->dispatch(new ArrayObject(['first']));
        $countBeforeRelease = count($received);

        $failures = $dispatcher->release();

        $this->assertSame(0, $countBeforeRelease);
        $this->assertCount(1, $received);
        $this->assertSame([], $failures);
    }

    public function testDiscardDropsTheKeptEvents(): void
    {
        $received = new ArrayObject();
        $dispatcher = $this->dispatcher($received);
        $dispatcher->defer();
        $dispatcher->dispatch(new ArrayObject(['first']));

        $dispatcher->discard();
        $dispatcher->dispatch(new ArrayObject(['after']));

        $this->assertCount(1, $received);
    }

    public function testAFailingListenerDoesNotStopTheNextEvents(): void
    {
        $eventDispatcher = new EventDispatcher();
        $calls = 0;
        $eventDispatcher->addListener(ArrayObject::class, static function () use (&$calls): void {
            ++$calls;
            if ($calls === 1) {
                throw new RuntimeException('Mailer down');
            }
        });
        $dispatcher = new LifecycleEventDispatcher($eventDispatcher);
        $dispatcher->defer();
        $dispatcher->dispatch(new ArrayObject());
        $dispatcher->dispatch(new ArrayObject());

        $failures = $dispatcher->release();

        $this->assertSame(2, $calls);
        $this->assertCount(1, $failures);
        $this->assertSame('Mailer down', $failures[0]->getMessage());
    }

    /**
     * @param ArrayObject<int, object> $received
     */
    protected function dispatcher(ArrayObject $received): LifecycleEventDispatcher
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(ArrayObject::class, static function (object $event) use ($received): void {
            $received[] = $event;
        });

        return new LifecycleEventDispatcher($eventDispatcher);
    }
}
