<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Enum\CustomerRole;

/**
 * Mapping and behaviour of SaasUserInterface, to use in the user entity of the application.
 */
trait SaasUserTrait
{
    #[ORM\ManyToOne(targetEntity: CustomerInterface::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected ?CustomerInterface $customer = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: CustomerRole::class)]
    protected ?CustomerRole $customerRole = null;

    public function getCustomer(): ?CustomerInterface
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerInterface $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getCustomerRole(): ?CustomerRole
    {
        return $this->customerRole;
    }

    public function setCustomerRole(?CustomerRole $customerRole): static
    {
        $this->customerRole = $customerRole;

        return $this;
    }

    public function isCustomerOwner(): bool
    {
        return $this->customerRole === CustomerRole::Owner;
    }

    public function getTenantId(): ?int
    {
        return $this->customer?->getTenantId();
    }
}
