<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Repository\StripeApiCallRepository;

/**
 * Journal of every call made by the bundle to the Stripe API, successful or not.
 */
#[ORM\Entity(repositoryClass: StripeApiCallRepository::class)]
#[ORM\Table(name: 'lle_saas_stripe_api_call')]
#[ORM\Index(name: 'lle_saas_stripe_api_call_operation_idx', fields: ['operation'])]
#[ORM\Index(name: 'lle_saas_stripe_api_call_success_idx', fields: ['success'])]
#[ORM\Index(name: 'lle_saas_stripe_api_call_created_at_idx', fields: ['createdAt'])]
#[ORM\Index(name: 'lle_saas_stripe_api_call_customer_idx', fields: ['stripeCustomerId'])]
class StripeApiCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    /** Called operation, as "<service>.<method>" of the Stripe SDK ("customers.create"). */
    #[ORM\Column(type: Types::STRING, length: 100)]
    protected ?string $operation = null;

    /** Id of the Stripe object targeted or created by the call. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $resourceId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $stripeCustomerId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $idempotencyKey = null;

    /** "Request-Id" header returned by Stripe, to find the call in the Stripe dashboard. */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    protected ?string $stripeRequestId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $httpStatus = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected bool $success = true;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    protected ?string $errorType = null;

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    protected ?string $errorCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $errorMessage = null;

    #[ORM\Column(type: Types::INTEGER)]
    protected int $durationMs = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $requestPayload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function setOperation(string $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function setResourceId(?string $resourceId): static
    {
        $this->resourceId = $resourceId;

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

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function setIdempotencyKey(?string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function getStripeRequestId(): ?string
    {
        return $this->stripeRequestId;
    }

    public function setStripeRequestId(?string $stripeRequestId): static
    {
        $this->stripeRequestId = $stripeRequestId;

        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): static
    {
        $this->httpStatus = $httpStatus;

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): static
    {
        $this->success = $success;

        return $this;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function setErrorType(?string $errorType): static
    {
        $this->errorType = $errorType;

        return $this;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): static
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequestPayload(): ?array
    {
        return $this->requestPayload;
    }

    /**
     * @param array<string, mixed>|null $requestPayload
     */
    public function setRequestPayload(?array $requestPayload): static
    {
        $this->requestPayload = $requestPayload;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
