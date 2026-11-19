<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Event\SubscriptionActivatedEvent;
use Lle\SaasBundle\Event\SubscriptionCanceledEvent;
use Lle\SaasBundle\Event\SubscriptionUpdatedEvent;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Exception\UnknownCustomerException;
use Lle\SaasBundle\Repository\CustomerRepository;
use Lle\SaasBundle\Repository\PlanRepository;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Stripe\StripeApiCallRecorder;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Stripe\StripeObjects;
use Lle\SaasBundle\Stripe\StripeTime;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Stripe\Customer as StripeCustomer;
use Stripe\Price;
use Stripe\StripeObject;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionItem;

/**
 * Copies a Stripe subscription into the local one (created if needed), then dispatches the lifecycle events.
 *
 * Customer of a new subscription: found by its Stripe id; otherwise, only for a subscription of this application
 * ("lle_saas_app" metadata), by the local id put in the metadata by the checkout. Any other subscription belongs to
 * another application of the Stripe account (ForeignStripeObjectException).
 *
 * Plan: kept while the Stripe price does not change (a lookup_key moved to a new price leaves the existing
 * subscriptions on their plan); otherwise found by price id, then lookup_key, then (creation only) the plan code of
 * the checkout metadata.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
#[WithMonologChannel(StripeApiCallRecorder::LOG_CHANNEL)]
class SubscriptionSynchronizer
{
    /** Subscription fields of the Stripe API versions before 2025-03-31.basil (now on the subscription items). */
    public const LEGACY_PERIOD_START = 'current_period_start';
    public const LEGACY_PERIOD_END = 'current_period_end';

    public function __construct(
        protected SubscriptionRepository $subscriptionRepository,
        protected CustomerRepository $customerRepository,
        protected PlanRepository $planRepository,
        protected EntityManagerInterface $entityManager,
        protected LifecycleEventDispatcher $eventDispatcher,
        protected StripeAppScope $appScope,
        protected StripeGatewayInterface $gateway,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ForeignStripeObjectException
     * @throws UnknownCustomerException
     */
    public function synchronize(StripeSubscription $stripeSubscription): Subscription
    {
        $subscription = $this->subscriptionRepository->findOneByStripeSubscriptionId($stripeSubscription->id);
        $previousStatus = $subscription?->getStatus();
        $previousPlan = $subscription?->getPlan();
        $previousFingerprint = $subscription === null ? null : $this->fingerprint($subscription);

        if ($subscription === null) {
            $subscription = new Subscription();
            $subscription
                ->setStripeSubscriptionId($stripeSubscription->id)
                ->setCustomer($this->resolveCustomer($stripeSubscription));
            $this->entityManager->persist($subscription);
        }

        $this->hydrate($subscription, $stripeSubscription, $previousStatus === null);
        $changed = $previousFingerprint !== $this->fingerprint($subscription);
        if ($changed) {
            $subscription->setUpdatedAt(new DateTimeImmutable());
        }
        $this->entityManager->flush();

        $this->dispatchLifecycleEvents($subscription, $previousStatus, $previousPlan, $changed);

        return $subscription;
    }

    protected function hydrate(Subscription $subscription, StripeSubscription $stripeSubscription, bool $isNew): void
    {
        $item = $stripeSubscription->items->data[0] ?? null;
        $price = $item?->price;
        $price = $price instanceof Price ? $price : null;

        $subscription
            ->setStatus($this->status($subscription, $stripeSubscription))
            ->setPlan($this->resolvePlan($subscription, $stripeSubscription, $price, $isNew))
            ->setStripePriceId($price?->id)
            ->setQuantity($item->quantity ?? 1)
            ->setCurrentPeriodStart(StripeTime::toDateTime($this->period($stripeSubscription, $item, self::LEGACY_PERIOD_START)))
            ->setCurrentPeriodEnd(StripeTime::toDateTime($this->period($stripeSubscription, $item, self::LEGACY_PERIOD_END)))
            ->setCancelAtPeriodEnd($stripeSubscription->cancel_at_period_end)
            ->setCancelAt(StripeTime::toDateTime($stripeSubscription->cancel_at))
            ->setCanceledAt(StripeTime::toDateTime($stripeSubscription->canceled_at))
            ->setEndedAt(StripeTime::toDateTime($stripeSubscription->ended_at));
    }

    /**
     * A status added by Stripe after this version of the bundle is logged; the subscription keeps its status
     * (a new one starts "incomplete": no access).
     */
    protected function status(Subscription $subscription, StripeSubscription $stripeSubscription): SubscriptionStatus
    {
        $status = SubscriptionStatus::tryFrom($stripeSubscription->status);
        if ($status === null) {
            $this->logger->warning('Unknown Stripe status "' . $stripeSubscription->status . '" of the subscription ' . $stripeSubscription->id, [
                'stripe_subscription_id' => $stripeSubscription->id,
            ]);
        }

        return $status ?? $subscription->getStatus();
    }

    /**
     * @throws ForeignStripeObjectException
     * @throws UnknownCustomerException
     */
    protected function resolveCustomer(StripeSubscription $stripeSubscription): CustomerInterface
    {
        $stripeCustomerId = $stripeSubscription->customer instanceof StripeCustomer
            ? $stripeSubscription->customer->id
            : $stripeSubscription->customer;

        $customer = $this->customerRepository->findOneByStripeCustomerId($stripeCustomerId);
        if ($customer !== null) {
            return $customer;
        }

        if (!$this->appScope->owns($stripeSubscription->metadata)) {
            throw new ForeignStripeObjectException($stripeSubscription->id);
        }

        $localId = $this->metadata($stripeSubscription->metadata, CustomerSynchronizer::METADATA_CUSTOMER_ID);
        $customer = $localId === null ? null : $this->customerRepository->find((int) $localId);
        if (!$customer instanceof CustomerInterface) {
            throw new UnknownCustomerException($stripeCustomerId);
        }

        if ($customer->getStripeCustomerId() === null) {
            $this->link($customer, $stripeCustomerId, $stripeSubscription);
        } else {
            // paid with another Stripe customer of the same local customer: access is given, the link is kept
            $this->logger->warning('Subscription ' . $stripeSubscription->id . ' paid by another Stripe customer of the customer', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $stripeCustomerId,
                'linked_stripe_customer_id' => $customer->getStripeCustomerId(),
            ]);
        }

        return $customer;
    }

    /**
     * A Stripe customer deleted meanwhile (event delivered late, replay) is not linked again: the next checkout would
     * fail on it.
     */
    protected function link(CustomerInterface $customer, string $stripeCustomerId, StripeSubscription $stripeSubscription): void
    {
        if (StripeObjects::isDeleted($this->gateway->retrieveCustomer($stripeCustomerId))) {
            $this->logger->warning('Stripe customer ' . $stripeCustomerId . ' deleted: not linked to the customer', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_customer_id' => $stripeCustomerId,
            ]);

            return;
        }

        $customer->setStripeCustomerId($stripeCustomerId);
    }

    protected function resolvePlan(Subscription $subscription, StripeSubscription $stripeSubscription, ?Price $price, bool $isNew): ?Plan
    {
        $currentPlan = $subscription->getPlan();
        if ($price !== null && $currentPlan !== null && $subscription->getStripePriceId() === $price->id) {
            return $currentPlan;
        }

        $plan = null;
        if ($price !== null) {
            $plan = $this->planRepository->findOneByStripePriceId($price->id);
            if ($plan === null && is_string($price->lookup_key) && $price->lookup_key !== '') {
                $plan = $this->planRepository->findOneByCode($price->lookup_key);
            }
        }

        if ($plan === null && $isNew) {
            $planCode = $this->metadata($stripeSubscription->metadata, CheckoutService::METADATA_PLAN_CODE);
            $plan = $planCode === null ? null : $this->planRepository->findOneByCode($planCode);
        }

        if ($plan === null) {
            $this->logger->warning('No local plan for the Stripe subscription ' . $stripeSubscription->id . ' (run lle:saas:plan:sync)', [
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_price_id' => $price?->id,
            ]);
        }

        return $plan;
    }

    protected function period(StripeSubscription $stripeSubscription, ?SubscriptionItem $item, string $field): ?int
    {
        $value = $item !== null && $item->offsetExists($field) ? $item[$field] : null;
        $value ??= $stripeSubscription->offsetExists($field) ? $stripeSubscription[$field] : null;

        return is_int($value) ? $value : null;
    }

    protected function metadata(?StripeObject $metadata, string $key): ?string
    {
        $value = $metadata !== null && $metadata->offsetExists($key) ? $metadata[$key] : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Values whose change makes a "subscription updated" event.
     *
     * @return array<string, mixed>
     */
    protected function fingerprint(Subscription $subscription): array
    {
        return [
            'status' => $subscription->getStatus(),
            'plan' => $subscription->getPlan(),
            'price' => $subscription->getStripePriceId(),
            'quantity' => $subscription->getQuantity(),
            'periodStart' => $subscription->getCurrentPeriodStart()?->getTimestamp(),
            'periodEnd' => $subscription->getCurrentPeriodEnd()?->getTimestamp(),
            'cancelAtPeriodEnd' => $subscription->isCancelAtPeriodEnd(),
            'cancelAt' => $subscription->getCancelAt()?->getTimestamp(),
            'canceledAt' => $subscription->getCanceledAt()?->getTimestamp(),
            'endedAt' => $subscription->getEndedAt()?->getTimestamp(),
        ];
    }

    /**
     * A subscription seen for the first time already ended (history imported by reconcile) dispatches nothing.
     */
    protected function dispatchLifecycleEvents(
        Subscription $subscription,
        ?SubscriptionStatus $previousStatus,
        ?Plan $previousPlan,
        bool $changed,
    ): void {
        $status = $subscription->getStatus();
        if ($previousStatus === null && $status->isTerminal()) {
            return;
        }

        if ($previousStatus !== null && $changed) {
            $this->eventDispatcher->dispatch(new SubscriptionUpdatedEvent($subscription, $previousStatus, $previousPlan));
        }
        if ($status->grantsAccess() && $previousStatus?->grantsAccess() !== true) {
            $this->eventDispatcher->dispatch(new SubscriptionActivatedEvent($subscription));
        }
        if ($status === SubscriptionStatus::Canceled && $previousStatus !== SubscriptionStatus::Canceled) {
            $this->eventDispatcher->dispatch(new SubscriptionCanceledEvent($subscription));
        }
    }
}
