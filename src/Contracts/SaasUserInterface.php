<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Contracts;

use Lle\SaasBundle\Enum\CustomerRole;

/**
 * Application user (the Connect user) attached to a customer account. Implemented with SaasUserTrait.
 *
 * getTenantId() has the same signature as the tenant contracts of HermesBundle (MultiTenantInterface)
 * and ConfigBundle (TenantInterface): the user entity can declare them too, without extra code.
 */
interface SaasUserInterface
{
    public function getCustomer(): ?CustomerInterface;

    public function setCustomer(?CustomerInterface $customer): static;

    public function getCustomerRole(): ?CustomerRole;

    public function setCustomerRole(?CustomerRole $customerRole): static;

    public function isCustomerOwner(): bool;

    public function getTenantId(): ?int;
}
