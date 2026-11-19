<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Repository\StripeEventRepository;

/**
 * Journal of every webhook event received from Stripe (handled or not), also used for idempotency.
 */
#[ORM\Entity(repositoryClass: StripeEventRepository::class)]
#[ORM\Table(name: 'lle_saas_stripe_event')]
#[ORM\Index(name: 'lle_saas_stripe_event_type_idx', fields: ['type'])]
#[ORM\Index(name: 'lle_saas_stripe_event_status_idx', fields: ['status'])]
#[ORM\Index(name: 'lle_saas_stripe_event_received_at_idx', fields: ['receivedAt'])]
#[ORM\Index(name: 'lle_saas_stripe_event_object_idx', fields: ['objectId'])]
#[ORM\Index(name: 'lle_saas_stripe_event_customer_idx', fields: ['stripeCustomerId'])]
class StripeEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    protected ?string $stripeEventId = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    protected ?string $type = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $livemode = false;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    protected ?string $apiVersion = null;

    /** Stripe type of the object carried by the event ("subscription", "invoice"...). */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    protected ?string $objectType = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $objectId = null;

    /** Stripe id rather than a relation: the event can arrive before the customer exists locally. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $stripeCustomerId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    protected array $payload = [];

    #[ORM\Column(type: Types::STRING, length: 20, enumType: StripeEventStatus::class)]
    protected StripeEventStatus $status = StripeEventStatus::Received;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $error = null;

    /** Creation date of the event on Stripe's side. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $stripeCreatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $receivedAt;

    /** Start of the current or last processing (a "processing" event claimed long ago is considered abandoned). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    protected ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $durationMs = null;

    public function __construct()
    {
        $this->receivedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStripeEventId(): ?string
    {
        return $this->stripeEventId;
    }

    public function setStripeEventId(string $stripeEventId): static
    {
        $this->stripeEventId = $stripeEventId;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isLivemode(): bool
    {
        return $this->livemode;
    }

    public function setLivemode(bool $livemode): static
    {
        $this->livemode = $livemode;

        return $this;
    }

    public function getApiVersion(): ?string
    {
        return $this->apiVersion;
    }

    public function setApiVersion(?string $apiVersion): static
    {
        $this->apiVersion = $apiVersion;

        return $this;
    }

    public function getObjectType(): ?string
    {
        return $this->objectType;
    }

    public function setObjectType(?string $objectType): static
    {
        $this->objectType = $objectType;

        return $this;
    }

    public function getObjectId(): ?string
    {
        return $this->objectId;
    }

    public function setObjectId(?string $objectId): static
    {
        $this->objectId = $objectId;

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
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;

        return $this;
    }

    public function getStatus(): StripeEventStatus
    {
        return $this->status;
    }

    public function setStatus(StripeEventStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function setAttempts(int $attempts): static
    {
        $this->attempts = $attempts;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function getStripeCreatedAt(): ?DateTimeImmutable
    {
        return $this->stripeCreatedAt;
    }

    public function setStripeCreatedAt(?DateTimeImmutable $stripeCreatedAt): static
    {
        $this->stripeCreatedAt = $stripeCreatedAt;

        return $this;
    }

    public function getReceivedAt(): DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getClaimedAt(): ?DateTimeImmutable
    {
        return $this->claimedAt;
    }

    public function setClaimedAt(?DateTimeImmutable $claimedAt): static
    {
        $this->claimedAt = $claimedAt;

        return $this;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?DateTimeImmutable $processedAt): static
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }
}
