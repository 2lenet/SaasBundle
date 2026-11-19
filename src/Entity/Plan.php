<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Enum\PlanInterval;
use Lle\SaasBundle\Repository\PlanRepository;
use Stringable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Offer sold by the SaaS: local copy of a Stripe price (and its product), Stripe being the source of truth.
 */
#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[ORM\Table(name: 'lle_saas_plan')]
class Plan implements Stringable
{
    public const DEFAULT_CURRENCY = 'eur';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    /** Stable code used by the application, taken from the "lookup_key" of the Stripe price. */
    #[ORM\Column(type: Types::STRING, length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    protected ?string $code = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    protected ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    protected ?string $stripeProductId = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    #[Assert\NotBlank]
    protected ?string $stripePriceId = null;

    /** Unit amount in the smallest currency unit (cents). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $amount = null;

    #[ORM\Column(type: Types::STRING, length: 3)]
    protected string $currency = self::DEFAULT_CURRENCY;

    #[ORM\Column(name: 'billing_interval', type: Types::STRING, length: 10, nullable: true, enumType: PlanInterval::class)]
    protected ?PlanInterval $interval = null;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $intervalCount = 1;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $active = true;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $syncedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function setStripeProductId(string $stripeProductId): static
    {
        $this->stripeProductId = $stripeProductId;

        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(string $stripePriceId): static
    {
        $this->stripePriceId = $stripePriceId;

        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getInterval(): ?PlanInterval
    {
        return $this->interval;
    }

    public function setInterval(?PlanInterval $interval): static
    {
        $this->interval = $interval;

        return $this;
    }

    public function getIntervalCount(): int
    {
        return $this->intervalCount;
    }

    public function setIntervalCount(int $intervalCount): static
    {
        $this->intervalCount = $intervalCount;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getSyncedAt(): ?DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
