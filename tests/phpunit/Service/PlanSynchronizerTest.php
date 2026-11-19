<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Service;

use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Enum\PlanInterval;
use Lle\SaasBundle\Repository\PlanRepository;
use Lle\SaasBundle\Service\PlanSynchronizer;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Tests\Repository\DatabaseTestCase;
use Stripe\Price;

class PlanSynchronizerTest extends DatabaseTestCase
{
    public const APP_ID = 'app_plans';

    public function testCreatesPlansFromStripePrices(): void
    {
        $synchronizer = $this->synchronizer([
            $this->price('price_starter', 'starter', 1900, 'month', productName: 'Starter', position: '2'),
        ]);

        $report = $synchronizer->synchronize();

        $this->assertSame(['starter'], $report->getCreated());
        $plan = $this->plan('starter');
        $this->assertSame('Starter', $plan->getLabel());
        $this->assertSame('Offre de démarrage', $plan->getDescription());
        $this->assertSame('prod_starter', $plan->getStripeProductId());
        $this->assertSame('price_starter', $plan->getStripePriceId());
        $this->assertSame(1900, $plan->getAmount());
        $this->assertSame('eur', $plan->getCurrency());
        $this->assertSame(PlanInterval::Month, $plan->getInterval());
        $this->assertSame(1, $plan->getIntervalCount());
        $this->assertSame(2, $plan->getPosition());
        $this->assertTrue($plan->isActive());
        $this->assertNotNull($plan->getSyncedAt());
    }

    public function testUpdatesAPlanWhenItsLookupKeyMovedToANewPrice(): void
    {
        $this->persistAndFlush($this->existingPlan('pro', 'price_pro_old'));
        $synchronizer = $this->synchronizer([$this->price('price_pro_new', 'pro', 4900, 'year')]);

        $report = $synchronizer->synchronize();

        $this->assertSame(['pro'], $report->getUpdated());
        $plan = $this->plan('pro');
        $this->assertSame('price_pro_new', $plan->getStripePriceId());
        $this->assertSame(4900, $plan->getAmount());
        $this->assertSame(PlanInterval::Year, $plan->getInterval());
    }

    public function testSkipsPricesWithoutLookupKey(): void
    {
        $synchronizer = $this->synchronizer([$this->price('price_orphan', null, 900, 'month')]);

        $report = $synchronizer->synchronize();

        $this->assertSame(['price_orphan'], $report->getSkipped());
        $this->assertSame([], $this->service(PlanRepository::class)->findAll());
    }

    public function testDeactivatesPlansNoLongerOnSale(): void
    {
        $this->persistAndFlush($this->existingPlan('legacy', 'price_legacy'));
        $synchronizer = $this->synchronizer([]);

        $report = $synchronizer->synchronize();

        $this->assertSame(['legacy'], $report->getDeactivated());
        $this->assertFalse($this->plan('legacy')->isActive());
    }

    public function testArchivedProductMakesThePlanInactive(): void
    {
        $synchronizer = $this->synchronizer([
            $this->price('price_old', 'old', 900, 'month', productActive: false),
        ]);

        $synchronizer->synchronize();

        $this->assertFalse($this->plan('old')->isActive());
    }

    public function testIgnoresThePricesOfOtherApplications(): void
    {
        $this->persistAndFlush($this->existingPlan('other', 'price_other'));
        $synchronizer = $this->synchronizer([
            $this->price('price_other', 'other', 900, 'month', productApp: 'another_app'),
            $this->price('price_untagged', 'untagged', 900, 'month', productApp: null),
            $this->price('price_tagged', 'tagged', 900, 'month', productApp: null, priceApp: self::APP_ID),
        ]);

        $report = $synchronizer->synchronize();

        $this->assertSame(2, $report->getForeign());
        $this->assertSame(['tagged'], $report->getCreated());
        $this->assertSame(['other'], $report->getDeactivated());
    }

    /**
     * @param list<Price> $prices
     */
    protected function synchronizer(array $prices): PlanSynchronizer
    {
        $gateway = $this->createStub(StripeGatewayInterface::class);
        $gateway->method('listActivePrices')->willReturn($prices);

        return new PlanSynchronizer($gateway, $this->service(PlanRepository::class), $this->entityManager, new StripeAppScope(self::APP_ID));
    }

    protected function price(
        string $priceId,
        ?string $lookupKey,
        int $amount,
        string $interval,
        string $productName = 'Offre',
        bool $productActive = true,
        ?string $position = null,
        ?string $productApp = self::APP_ID,
        ?string $priceApp = null,
    ): Price {
        $priceMetadata = $position === null ? [] : [PlanSynchronizer::METADATA_POSITION => $position];
        if ($priceApp !== null) {
            $priceMetadata[StripeAppScope::METADATA_APP] = $priceApp;
        }

        $code = $lookupKey ?? 'orphan';

        return Price::constructFrom([
            'id' => $priceId,
            'object' => 'price',
            'active' => true,
            'lookup_key' => $lookupKey,
            'unit_amount' => $amount,
            'currency' => 'eur',
            'recurring' => ['interval' => $interval, 'interval_count' => 1],
            'metadata' => $priceMetadata,
            'product' => [
                'id' => 'prod_' . $code,
                'object' => 'product',
                'name' => $productName,
                'description' => 'Offre de démarrage',
                'active' => $productActive,
                'metadata' => $productApp === null ? [] : [StripeAppScope::METADATA_APP => $productApp],
            ],
        ]);
    }

    protected function existingPlan(string $code, string $priceId): Plan
    {
        $plan = new Plan();
        $plan
            ->setCode($code)
            ->setLabel(ucfirst($code))
            ->setStripeProductId('prod_' . $code)
            ->setStripePriceId($priceId)
            ->setAmount(100)
            ->setInterval(PlanInterval::Month);

        return $plan;
    }

    protected function plan(string $code): Plan
    {
        $this->entityManager->clear();
        $plan = $this->service(PlanRepository::class)->findOneByCode($code);
        $this->assertNotNull($plan);

        return $plan;
    }
}
