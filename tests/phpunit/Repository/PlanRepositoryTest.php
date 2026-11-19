<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Repository;

use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Enum\PlanInterval;
use Lle\SaasBundle\Repository\PlanRepository;

class PlanRepositoryTest extends DatabaseTestCase
{
    public const PRICE_STARTER = 'price_starter';
    public const PRICE_PRO = 'price_pro';
    public const PRICE_LEGACY = 'price_legacy';

    public function testFindOneByStripePriceId(): void
    {
        $this->persistAndFlush($this->plan('starter', self::PRICE_STARTER, 1, true));
        $repository = $this->service(PlanRepository::class);

        $plan = $repository->findOneByStripePriceId(self::PRICE_STARTER);

        $this->assertNotNull($plan);
        $this->assertSame('starter', $plan->getCode());
        $this->assertSame(PlanInterval::Month, $plan->getInterval());
    }

    public function testFindActiveIsOrderedByPositionAndSkipsInactivePlans(): void
    {
        $this->persistAndFlush(
            $this->plan('pro', self::PRICE_PRO, 2, true),
            $this->plan('legacy', self::PRICE_LEGACY, 0, false),
            $this->plan('starter', self::PRICE_STARTER, 1, true),
        );
        $repository = $this->service(PlanRepository::class);

        $codes = array_map(static fn (Plan $plan): ?string => $plan->getCode(), $repository->findActive());

        $this->assertSame(['starter', 'pro'], $codes);
    }

    protected function plan(string $code, string $priceId, int $position, bool $active): Plan
    {
        $plan = new Plan();
        $plan
            ->setCode($code)
            ->setLabel(ucfirst($code))
            ->setStripeProductId('prod_' . $code)
            ->setStripePriceId($priceId)
            ->setAmount(1900)
            ->setCurrency('eur')
            ->setInterval(PlanInterval::Month)
            ->setPosition($position)
            ->setActive($active);

        return $plan;
    }
}
