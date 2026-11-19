<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the tenant (= customer) of the connected user.
 */
class TenantProvider
{
    public function __construct(
        protected TokenStorageInterface $tokenStorage,
    ) {
    }

    public function getCurrentUser(): ?SaasUserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof SaasUserInterface ? $user : null;
    }

    public function getCurrentCustomer(): ?CustomerInterface
    {
        return $this->getCurrentUser()?->getCustomer();
    }

    public function getTenantId(): ?int
    {
        return $this->getCurrentCustomer()?->getTenantId();
    }
}
