<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Dto\PlanSyncReport;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Enum\PlanInterval;
use Lle\SaasBundle\Repository\PlanRepository;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Product;

/**
 * Copies the recurring prices on sale in Stripe into the local plans.
 *
 * The plan code is the "lookup_key" of the price: moving a lookup_key to a new price in Stripe updates the
 * existing plan (and its subscriptions keep pointing to it). Plans whose price is no longer on sale are deactivated.
 * Only the prices of this application are taken: "lle_saas_app" metadata on the product or on the price.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class PlanSynchronizer
{
    /** Optional metadata of the Stripe price giving the display order of the plan. */
    public const METADATA_POSITION = 'position';

    public function __construct(
        protected StripeGatewayInterface $gateway,
        protected PlanRepository $planRepository,
        protected EntityManagerInterface $entityManager,
        protected StripeAppScope $appScope,
    ) {
    }

    /**
     * @throws ApiErrorException
     */
    public function synchronize(): PlanSyncReport
    {
        $report = new PlanSyncReport();
        $now = new DateTimeImmutable();

        $plansByPriceId = [];
        $plansByCode = [];
        foreach ($this->planRepository->findAll() as $plan) {
            $plansByPriceId[(string) $plan->getStripePriceId()] = $plan;
            $plansByCode[(string) $plan->getCode()] = $plan;
        }

        $seenPriceIds = [];
        foreach ($this->gateway->listActivePrices() as $price) {
            if (!$this->belongsToTheApplication($price)) {
                $report->addForeign();
                continue;
            }
            $lookupKey = $price->lookup_key;
            if (!is_string($lookupKey) || $lookupKey === '') {
                $report->addSkipped($price->id);
                continue;
            }

            $plan = $plansByPriceId[$price->id] ?? $plansByCode[$lookupKey] ?? null;
            if ($plan === null) {
                $plan = new Plan();
                $this->entityManager->persist($plan);
                $report->addCreated($lookupKey);
            } else {
                $report->addUpdated($lookupKey);
            }

            $this->hydrate($plan, $price, $lookupKey, $now);
            $seenPriceIds[$price->id] = true;
        }

        foreach ($plansByCode as $plan) {
            if ($plan->isActive() && !isset($seenPriceIds[(string) $plan->getStripePriceId()])) {
                $plan->setActive(false)->setSyncedAt($now);
                $report->addDeactivated((string) $plan->getCode());
            }
        }

        $this->entityManager->flush();

        return $report;
    }

    /**
     * The "lle_saas_app" metadata is set on the product (all its prices) or on the price itself.
     */
    protected function belongsToTheApplication(Price $price): bool
    {
        $product = $price->product;

        return $this->appScope->owns($price->metadata)
            || ($product instanceof Product && $this->appScope->owns($product->metadata));
    }

    protected function hydrate(Plan $plan, Price $price, string $lookupKey, DateTimeImmutable $now): void
    {
        $product = $price->product;
        $productIsObject = $product instanceof Product;
        $productActive = !$productIsObject || $product->active;

        $plan
            ->setCode($lookupKey)
            ->setLabel($productIsObject ? $product->name : $lookupKey)
            ->setDescription($productIsObject ? $product->description : null)
            ->setStripeProductId($productIsObject ? $product->id : $product)
            ->setStripePriceId($price->id)
            ->setAmount($price->unit_amount)
            ->setCurrency($price->currency)
            ->setInterval(PlanInterval::tryFrom((string) $price->recurring?->interval))
            ->setIntervalCount($price->recurring->interval_count ?? 1)
            ->setPosition((int) ($price->metadata[self::METADATA_POSITION] ?? 0))
            ->setActive($price->active && $productActive)
            ->setSyncedAt($now);
    }
}
