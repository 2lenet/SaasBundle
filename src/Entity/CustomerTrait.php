<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mapping and behaviour of CustomerInterface. An entity using it must call initializeCustomer() in its constructor.
 */
trait CustomerTrait
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    protected ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    protected ?string $billingEmail = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true, nullable: true)]
    #[Assert\Length(max: 255)]
    protected ?string $stripeCustomerId = null;

    /** @var Collection<int, SaasUserInterface> */
    #[ORM\OneToMany(targetEntity: SaasUserInterface::class, mappedBy: 'customer')]
    protected Collection $users;

    /** @var Collection<int, Subscription> */
    #[ORM\OneToMany(targetEntity: Subscription::class, mappedBy: 'customer')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    protected Collection $subscriptions;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getTenantId(): ?int
    {
        return $this->getId();
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getBillingEmail(): ?string
    {
        return $this->billingEmail;
    }

    public function setBillingEmail(?string $billingEmail): static
    {
        $this->billingEmail = $billingEmail;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    /**
     * @return Collection<int, SaasUserInterface>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(SaasUserInterface $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setCustomer($this);
        }

        return $this;
    }

    public function removeUser(SaasUserInterface $user): static
    {
        if ($this->users->removeElement($user) && $user->getCustomer() === $this) {
            $user->setCustomer(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function getSubscriptions(): Collection
    {
        return $this->subscriptions;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    protected function initializeCustomer(): void
    {
        $this->users = new ArrayCollection();
        $this->subscriptions = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }
}
