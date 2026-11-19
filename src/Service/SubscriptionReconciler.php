<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Lle\SaasBundle\Doctrine\EntityManagerResetter;
use Lle\SaasBundle\Dto\ReconcileReport;
use Lle\SaasBundle\Entity\Subscription;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Repository\CustomerRepository;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Stripe\Customer as StripeCustomer;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * Compares the local subscriptions with Stripe (safety net for lost webhooks):
 *  - "missing": running Stripe subscription of this application, absent locally (ended ones are history: skipped);
 *  - "drifted": status, price (of the subscription item) or cancellation at period end different;
 *  - "orphan": local subscription still running, unknown to Stripe (reported only).
 * With $fix, missing and drifted subscriptions are synchronized (lifecycle events dispatched).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class SubscriptionReconciler
{
    public const DIFFERENCE_SEPARATOR = ', ';
    public const NONE = '-';

    public function __construct(
        protected StripeGatewayInterface $gateway,
        protected SubscriptionRepository $subscriptionRepository,
        protected CustomerRepository $customerRepository,
        protected SubscriptionSynchronizer $subscriptionSynchronizer,
        protected StripeAppScope $appScope,
        protected EntityManagerResetter $entityManagers,
    ) {
    }

    /**
     * @throws ApiErrorException
     */
    public function reconcile(bool $fix): ReconcileReport
    {
        $report = new ReconcileReport();

        $locals = [];
        foreach ($this->subscriptionRepository->findAll() as $subscription) {
            $locals[(string) $subscription->getStripeSubscriptionId()] = $subscription;
        }
        $localStripeCustomerIds = $this->customerRepository->findStripeCustomerIds();

        $seen = [];
        foreach ($this->gateway->listSubscriptions() as $stripeSubscription) {
            $seen[$stripeSubscription->id] = true;
            $local = $locals[$stripeSubscription->id] ?? null;

            if ($local === null) {
                if (!$this->belongsToTheApplication($stripeSubscription, $localStripeCustomerIds)) {
                    $report->addForeign();
                    continue;
                }
                if (SubscriptionStatus::tryFrom($stripeSubscription->status)?->isTerminal() === true) {
                    // ended before the application knew it (history): nothing to give access to
                    continue;
                }
                $report->addMissing($stripeSubscription->id, $stripeSubscription->status);
            } else {
                $differences = $this->differences($local, $stripeSubscription);
                if ($differences === []) {
                    continue;
                }
                $report->addDrifted($stripeSubscription->id, implode(self::DIFFERENCE_SEPARATOR, $differences));
            }

            if ($fix) {
                $this->fix($stripeSubscription, $report);
            }
        }

        foreach ($locals as $stripeSubscriptionId => $local) {
            if (!isset($seen[$stripeSubscriptionId]) && !$local->getStatus()->isTerminal()) {
                $report->addOrphan($stripeSubscriptionId);
            }
        }

        return $report;
    }

    protected function fix(StripeSubscription $stripeSubscription, ReconcileReport $report): void
    {
        try {
            $this->subscriptionSynchronizer->synchronize($stripeSubscription);
            $report->addFixed($stripeSubscription->id);
        } catch (Throwable $exception) {
            $report->addFailed($stripeSubscription->id, $exception->getMessage());
            // a database error closes the entity manager: reopened for the next subscriptions
            $this->entityManagers->openManagerFor(Subscription::class);
        }
    }

    /**
     * @param array<string, true> $localStripeCustomerIds
     */
    protected function belongsToTheApplication(StripeSubscription $stripeSubscription, array $localStripeCustomerIds): bool
    {
        $customer = $stripeSubscription->customer;
        $stripeCustomerId = $customer instanceof StripeCustomer ? $customer->id : $customer;
        if (isset($localStripeCustomerIds[$stripeCustomerId])) {
            return true;
        }

        return $this->appScope->owns($stripeSubscription->metadata);
    }

    /**
     * @return list<string>
     */
    protected function differences(Subscription $local, StripeSubscription $stripeSubscription): array
    {
        $differences = [];
        if ($local->getStatus()->value !== $stripeSubscription->status) {
            $differences[] = 'status ' . $local->getStatus()->value . ' → ' . $stripeSubscription->status;
        }

        $price = ($stripeSubscription->items->data[0] ?? null)?->price;
        $stripePriceId = $price instanceof Price ? $price->id : null;
        $localPriceId = $local->getStripePriceId();
        if ($stripePriceId !== null && $localPriceId !== $stripePriceId) {
            $differences[] = 'price ' . ($localPriceId ?? self::NONE) . ' → ' . $stripePriceId;
        }

        if ($local->isCancelAtPeriodEnd() !== $stripeSubscription->cancel_at_period_end) {
            $differences[] = 'cancel_at_period_end → ' . ($stripeSubscription->cancel_at_period_end ? 'true' : 'false');
        }

        return $differences;
    }
}
