<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook\Handler;

use Lle\SaasBundle\Event\InvoicePaidEvent;
use Lle\SaasBundle\Event\PaymentFailedEvent;
use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Repository\CustomerRepository;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Service\LifecycleEventDispatcher;
use Lle\SaasBundle\Service\SubscriptionSynchronizer;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Webhook\StripeEventHandlerInterface;
use Lle\SaasBundle\Webhook\StripeEventType;
use Stripe\Event;
use Stripe\Invoice;
use Stripe\StripeObject;

/**
 * Tells the application about paid invoices and failed payments. The subscription status itself is updated by the
 * "customer.subscription.updated" event that Stripe sends alongside. The invoice of a Stripe customer unknown locally
 * belongs to another application of the account.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class InvoiceEventHandler implements StripeEventHandlerInterface
{
    /** Invoice field of the Stripe API versions before 2025-03-31.basil (now under parent.subscription_details). */
    public const LEGACY_SUBSCRIPTION = 'subscription';
    public const PARENT = 'parent';

    public function __construct(
        protected CustomerRepository $customerRepository,
        protected SubscriptionRepository $subscriptionRepository,
        protected LifecycleEventDispatcher $eventDispatcher,
        protected StripeGatewayInterface $gateway,
        protected SubscriptionSynchronizer $subscriptionSynchronizer,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return $eventType === StripeEventType::INVOICE_PAID || $eventType === StripeEventType::INVOICE_PAYMENT_FAILED;
    }

    public function handle(Event $event): void
    {
        $invoice = $event->data->object;
        if (!$invoice instanceof Invoice) {
            return;
        }

        $stripeCustomerId = $this->id($invoice->customer);
        $customer = $stripeCustomerId === null ? null : $this->customerRepository->findOneByStripeCustomerId($stripeCustomerId);
        if ($customer === null) {
            throw new ForeignStripeObjectException($invoice->id);
        }
        $stripeSubscriptionId = $this->stripeSubscriptionId($invoice);
        $subscription = $stripeSubscriptionId === null
            ? null
            : $this->subscriptionRepository->findOneByStripeSubscriptionId($stripeSubscriptionId);
        if ($stripeSubscriptionId !== null && $subscription === null) {
            // the first invoice often arrives before the subscription events: synchronized first
            $subscription = $this->subscriptionSynchronizer->synchronize($this->gateway->retrieveSubscription($stripeSubscriptionId));
        }

        $this->eventDispatcher->dispatch($event->type === StripeEventType::INVOICE_PAID
            ? new InvoicePaidEvent($invoice, $customer, $subscription)
            : new PaymentFailedEvent($invoice, $customer, $subscription));
    }

    protected function stripeSubscriptionId(Invoice $invoice): ?string
    {
        $subscription = null;
        if ($invoice->offsetExists(self::PARENT)) {
            $subscription = $invoice->parent?->subscription_details?->subscription;
        }
        if ($subscription === null && $invoice->offsetExists(self::LEGACY_SUBSCRIPTION)) {
            $subscription = $invoice[self::LEGACY_SUBSCRIPTION];
        }

        return $this->id($subscription);
    }

    protected function id(mixed $reference): ?string
    {
        if ($reference instanceof StripeObject) {
            $reference = $reference->id;
        }

        return is_string($reference) && $reference !== '' ? $reference : null;
    }
}
