<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Repository;

use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Repository\StripeApiCallRepository;
use Lle\SaasBundle\Repository\StripeEventRepository;

class StripeEventRepositoryTest extends DatabaseTestCase
{
    public const EVENT_TYPE = 'customer.subscription.updated';

    public function testFindOneByStripeEventIdKeepsThePayload(): void
    {
        $event = $this->event('evt_1', StripeEventStatus::Received);
        $event->setPayload(['id' => 'evt_1', 'data' => ['object' => ['id' => 'sub_1']]]);
        $this->persistAndFlush($event);
        $repository = $this->service(StripeEventRepository::class);

        $found = $repository->findOneByStripeEventId('evt_1');

        $this->assertNotNull($found);
        $this->assertSame(self::EVENT_TYPE, $found->getType());
        $this->assertSame('sub_1', $found->getPayload()['data']['object']['id']);
        $this->assertNull($repository->findOneByStripeEventId('evt_unknown'));
    }

    public function testFindFailed(): void
    {
        $this->persistAndFlush(
            $this->event('evt_ok', StripeEventStatus::Processed),
            $this->event('evt_ko', StripeEventStatus::Failed),
            $this->event('evt_ignored', StripeEventStatus::Ignored),
        );
        $repository = $this->service(StripeEventRepository::class);

        $failed = $repository->findFailed();

        $this->assertCount(1, $failed);
        $this->assertSame('evt_ko', $failed[0]->getStripeEventId());
    }

    public function testApiCallIsJournaled(): void
    {
        $call = new StripeApiCall();
        $call
            ->setOperation('customers.create')
            ->setSuccess(false)
            ->setHttpStatus(402)
            ->setErrorType('card_error')
            ->setErrorMessage('Your card was declined.')
            ->setDurationMs(120)
            ->setRequestPayload(['email' => 'jane@acme.test']);
        $this->persistAndFlush($call);
        $repository = $this->service(StripeApiCallRepository::class);

        $found = $repository->findAll();

        $this->assertCount(1, $found);
        $this->assertFalse($found[0]->isSuccess());
        $this->assertSame(402, $found[0]->getHttpStatus());
        $this->assertSame(['email' => 'jane@acme.test'], $found[0]->getRequestPayload());
    }

    protected function event(string $stripeEventId, StripeEventStatus $status): StripeEvent
    {
        $event = new StripeEvent();
        $event->setStripeEventId($stripeEventId)->setType(self::EVENT_TYPE)->setStatus($status);

        return $event;
    }
}
