<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Event;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Entity\Subscription;
use Stripe\Invoice;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Stripe invoice event of a local customer, with the local subscription when it is known.
 */
abstract class InvoiceEvent extends Event
{
    public function __construct(
        protected Invoice $invoice,
        protected CustomerInterface $customer,
        protected ?Subscription $subscription,
    ) {
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getCustomer(): CustomerInterface
    {
        return $this->customer;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }
}
