<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Contracts;

use DateTimeImmutable;
use Doctrine\Common\Collections\Collection;
use Lle\SaasBundle\Entity\Subscription;
use Stringable;

/**
 * Billable account of the SaaS, and tenant of its data (tenant id = customer id).
 *
 * Implemented by Lle\SaasBundle\Entity\Customer; an application can replace it with its own entity using
 * CustomerTrait and the "lle_saas.customer_class" option.
 */
interface CustomerInterface extends Stringable
{
    public function getId(): ?int;

    public function getTenantId(): ?int;

    public function getName(): ?string;

    public function setName(string $name): static;

    public function getBillingEmail(): ?string;

    public function setBillingEmail(?string $billingEmail): static;

    public function getStripeCustomerId(): ?string;

    public function setStripeCustomerId(?string $stripeCustomerId): static;

    /**
     * @return Collection<int, SaasUserInterface>
     */
    public function getUsers(): Collection;

    public function addUser(SaasUserInterface $user): static;

    public function removeUser(SaasUserInterface $user): static;

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection;

    public function getCreatedAt(): DateTimeImmutable;
}
