<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Webhook\Handler;

use Lle\SaasBundle\Exception\ForeignStripeObjectException;
use Lle\SaasBundle\Repository\CustomerRepository;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Lle\SaasBundle\Stripe\StripeObjects;
use Lle\SaasBundle\Webhook\StripeEventHandlerInterface;
use Lle\SaasBundle\Webhook\StripeEventType;
use Stripe\Customer as StripeCustomer;
use Stripe\Event;

/**
 * Billing email changed in the Customer Portal is copied locally; a Stripe customer deleted is unlinked
 * (its subscriptions end through their own events). The name stays owned by the application.
 * A Stripe customer unknown locally belongs to another application of the account.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class CustomerEventHandler implements StripeEventHandlerInterface
{
    public function __construct(
        protected CustomerRepository $customerRepository,
        protected StripeGatewayInterface $gateway,
    ) {
    }

    public function supports(string $eventType): bool
    {
        return $eventType === StripeEventType::CUSTOMER_UPDATED || $eventType === StripeEventType::CUSTOMER_DELETED;
    }

    public function handle(Event $event): void
    {
        $stripeCustomer = $event->data->object;
        if (!$stripeCustomer instanceof StripeCustomer) {
            return;
        }

        $customer = $this->customerRepository->findOneByStripeCustomerId($stripeCustomer->id);
        if ($customer === null) {
            throw new ForeignStripeObjectException($stripeCustomer->id);
        }

        if ($event->type === StripeEventType::CUSTOMER_DELETED) {
            $customer->setStripeCustomerId(null);

            return;
        }

        // events are not ordered: the current email is read from Stripe rather than from an old payload
        $current = $this->gateway->retrieveCustomer($stripeCustomer->id);
        if (StripeObjects::isDeleted($current)) {
            $customer->setStripeCustomerId(null);

            return;
        }
        if ($current->email !== null && $current->email !== '') {
            $customer->setBillingEmail($current->email);
        }
    }
}
