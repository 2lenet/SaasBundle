<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Exception\NoStripeCustomerException;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Stripe\BillingPortal\Session;
use Stripe\Exception\ApiErrorException;

/**
 * Opens the Stripe customer portal: payment method, invoices, plan change and cancellation.
 */
class BillingPortalService
{
    public function __construct(
        protected StripeGatewayInterface $gateway,
    ) {
    }

    /**
     * @throws NoStripeCustomerException
     * @throws ApiErrorException
     */
    public function createSession(CustomerInterface $customer, string $returnUrl): Session
    {
        $stripeCustomerId = $customer->getStripeCustomerId();
        if ($stripeCustomerId === null) {
            throw new NoStripeCustomerException();
        }

        return $this->gateway->createBillingPortalSession([
            StripeGatewayInterface::PARAM_CUSTOMER => $stripeCustomerId,
            'return_url' => $returnUrl,
        ]);
    }
}
