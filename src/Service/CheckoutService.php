<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use Lle\SaasBundle\Exception\AlreadySubscribedException;
use Lle\SaasBundle\Exception\PlanNotAvailableException;
use Lle\SaasBundle\Repository\SubscriptionRepository;
use Lle\SaasBundle\Stripe\StripeAppScope;
use Lle\SaasBundle\Stripe\StripeGatewayInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

/**
 * Opens a Stripe Checkout session to subscribe a customer to a plan.
 *
 * The local subscription is not created here: it comes from the webhooks once the payment is confirmed.
 * One subscription per customer: refused while one is running, locally or in Stripe (two tabs can still both open a
 * session before paying: enable "limit customers to one subscription" in the Stripe Checkout settings).
 */
class CheckoutService
{
    public const MODE_SUBSCRIPTION = 'subscription';
    public const METADATA_PLAN_CODE = 'lle_saas_plan_code';
    public const SESSION_ID_QUERY = 'session_id={CHECKOUT_SESSION_ID}';
    public const QUANTITY = 1;

    public function __construct(
        protected StripeGatewayInterface $gateway,
        protected CustomerSynchronizer $customerSynchronizer,
        protected SubscriptionRepository $subscriptionRepository,
        protected StripeAppScope $appScope,
    ) {
    }

    /**
     * @param string $successUrl absolute URL; the Checkout session id is appended as "session_id"
     *
     * @throws PlanNotAvailableException
     * @throws AlreadySubscribedException
     * @throws ApiErrorException
     */
    public function createSubscriptionSession(
        CustomerInterface $customer,
        Plan $plan,
        string $successUrl,
        string $cancelUrl,
    ): Session {
        if (!$plan->isActive()) {
            throw new PlanNotAvailableException();
        }
        if ($this->subscriptionRepository->findAccessGrantingForCustomer($customer) !== []) {
            throw new AlreadySubscribedException();
        }
        $stripeCustomerId = $customer->getStripeCustomerId();
        if ($stripeCustomerId === null) {
            $this->customerSynchronizer->synchronize($customer);
        } elseif ($this->hasRunningStripeSubscription($stripeCustomerId)) {
            throw new AlreadySubscribedException();
        }

        $metadata = [
            CustomerSynchronizer::METADATA_CUSTOMER_ID => (string) $customer->getId(),
            self::METADATA_PLAN_CODE => (string) $plan->getCode(),
        ] + $this->appScope->metadata();

        return $this->gateway->createCheckoutSession([
            'mode' => self::MODE_SUBSCRIPTION,
            StripeGatewayInterface::PARAM_CUSTOMER => $customer->getStripeCustomerId(),
            'client_reference_id' => (string) $customer->getId(),
            'line_items' => [['price' => $plan->getStripePriceId(), 'quantity' => self::QUANTITY]],
            'success_url' => $this->withSessionId($successUrl),
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
        ]);
    }

    /**
     * Stripe is the reference: a subscription may exist there before its webhook arrives (checkout paid in another
     * tab), or be pending / unpaid / paused (no access, but still billed). Only an ended one allows a new checkout.
     *
     * @throws ApiErrorException
     */
    protected function hasRunningStripeSubscription(string $stripeCustomerId): bool
    {
        foreach ($this->gateway->listCustomerSubscriptions($stripeCustomerId) as $stripeSubscription) {
            if (SubscriptionStatus::tryFrom($stripeSubscription->status)?->isTerminal() !== true) {
                return true;
            }
        }

        return false;
    }

    protected function withSessionId(string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . self::SESSION_ID_QUERY;
    }
}
