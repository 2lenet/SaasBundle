<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Exception\AlreadySubscribedException;
use Lle\SaasBundle\Exception\NoStripeCustomerException;
use Lle\SaasBundle\Exception\PlanNotAvailableException;
use Lle\SaasBundle\Repository\PlanRepository;
use Lle\SaasBundle\Security\CustomerBillingVoter;
use Lle\SaasBundle\Security\SaasRole;
use Lle\SaasBundle\Service\BillingPortalService;
use Lle\SaasBundle\Service\CheckoutService;
use Lle\SaasBundle\Service\TenantProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Redirects the customer owner to the Stripe hosted pages. Forms post here with a CSRF token:
 * csrf_token('lle_saas_checkout') / csrf_token('lle_saas_billing_portal') in a "_token" field.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class BillingController extends AbstractController
{
    public const ROUTE_CHECKOUT = 'lle_saas_checkout';
    public const ROUTE_BILLING_PORTAL = 'lle_saas_billing_portal';
    public const CSRF_CHECKOUT = 'lle_saas_checkout';
    public const CSRF_BILLING_PORTAL = 'lle_saas_billing_portal';

    public const REDIRECT_CHECKOUT_SUCCESS = 'checkout_success';
    public const REDIRECT_CHECKOUT_CANCEL = 'checkout_cancel';
    public const REDIRECT_BILLING_PORTAL_RETURN = 'billing_portal_return';

    /**
     * @param array<string, string> $redirectRoutes application routes, see the "lle_saas.routes" option
     */
    public function __construct(
        protected CheckoutService $checkoutService,
        protected BillingPortalService $billingPortalService,
        protected PlanRepository $planRepository,
        protected TenantProvider $tenantProvider,
        protected array $redirectRoutes,
    ) {
    }

    #[Route('/checkout/{code}', name: self::ROUTE_CHECKOUT, methods: [Request::METHOD_POST])]
    #[IsGranted(SaasRole::CUSTOMER_ACTION_CHECKOUT)]
    #[IsGranted(CustomerBillingVoter::MANAGE_BILLING)]
    #[IsCsrfTokenValid(self::CSRF_CHECKOUT)]
    public function checkout(string $code): RedirectResponse
    {
        $plan = $this->planRepository->findOneByCode($code);
        if ($plan === null) {
            throw $this->createNotFoundException();
        }

        try {
            $session = $this->checkoutService->createSubscriptionSession(
                $this->currentCustomer(),
                $plan,
                $this->absoluteUrl(self::REDIRECT_CHECKOUT_SUCCESS),
                $this->absoluteUrl(self::REDIRECT_CHECKOUT_CANCEL),
            );
        } catch (PlanNotAvailableException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        } catch (AlreadySubscribedException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        return $this->redirect((string) $session->url, Response::HTTP_SEE_OTHER);
    }

    #[Route('/billing-portal', name: self::ROUTE_BILLING_PORTAL, methods: [Request::METHOD_POST])]
    #[IsGranted(SaasRole::CUSTOMER_ACTION_BILLINGPORTAL)]
    #[IsGranted(CustomerBillingVoter::MANAGE_BILLING)]
    #[IsCsrfTokenValid(self::CSRF_BILLING_PORTAL)]
    public function billingPortal(): RedirectResponse
    {
        try {
            $session = $this->billingPortalService->createSession(
                $this->currentCustomer(),
                $this->absoluteUrl(self::REDIRECT_BILLING_PORTAL_RETURN),
            );
        } catch (NoStripeCustomerException $exception) {
            throw $this->createNotFoundException($exception->getMessage(), $exception);
        }

        return $this->redirect($session->url, Response::HTTP_SEE_OTHER);
    }

    protected function currentCustomer(): CustomerInterface
    {
        $customer = $this->tenantProvider->getCurrentCustomer();
        if ($customer === null) {
            throw $this->createAccessDeniedException();
        }

        return $customer;
    }

    protected function absoluteUrl(string $redirect): string
    {
        return $this->generateUrl($this->redirectRoutes[$redirect], [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
