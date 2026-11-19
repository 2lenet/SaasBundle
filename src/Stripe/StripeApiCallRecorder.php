<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Stripe;

use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Monitoring\Duration;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use Throwable;

/**
 * Runs a Stripe API call and records it: journal (StripeApiCall) and "saas_stripe" log.
 * A failure of the journal itself is logged and never breaks the Stripe call.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
#[WithMonologChannel(self::LOG_CHANNEL)]
class StripeApiCallRecorder
{
    public const LOG_CHANNEL = 'saas_stripe';
    public const REQUEST_ID_HEADER = 'Request-Id';

    public function __construct(
        protected StripeApiCallJournalInterface $journal,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param array<string, mixed> $params
     * @param callable(): T $call
     *
     * @return T
     *
     * @throws ApiErrorException
     */
    public function record(
        string $operation,
        array $params,
        callable $call,
        ?string $stripeCustomerId = null,
        ?string $idempotencyKey = null,
    ): mixed {
        $entry = new StripeApiCall();
        $entry
            ->setOperation($operation)
            ->setRequestPayload($params)
            ->setStripeCustomerId($stripeCustomerId)
            ->setIdempotencyKey($idempotencyKey);
        $start = Duration::start();

        try {
            $result = $call();
        } catch (ApiErrorException $exception) {
            $this->fillFailure($entry, $exception, $start);
            $this->logger->error('Stripe API call ' . $operation . ' failed: ' . $exception->getMessage(), $this->context($entry));
            $this->write($entry);

            throw $exception;
        }

        $this->fillSuccess($entry, $result, $start);
        $this->logger->info('Stripe API call ' . $operation, $this->context($entry));
        $this->write($entry);

        return $result;
    }

    protected function fillSuccess(StripeApiCall $entry, mixed $result, int|float $start): void
    {
        $entry->setSuccess(true)->setDurationMs(Duration::millisecondsSince($start));
        if (!$result instanceof StripeObject) {
            return;
        }

        $response = $result->getLastResponse();
        if ($response !== null) {
            $requestId = $response->headers[self::REQUEST_ID_HEADER] ?? null;
            $entry->setHttpStatus($response->code)->setStripeRequestId(is_string($requestId) ? $requestId : null);
        }

        if ($result instanceof Collection || !isset($result->id)) {
            return;
        }
        $entry->setResourceId($result->id);
        if ($entry->getStripeCustomerId() !== null) {
            return;
        }
        if ($result instanceof Customer) {
            $entry->setStripeCustomerId($result->id);
        } elseif ($result->offsetExists(StripeObjects::CUSTOMER_FIELD) && is_string($result[StripeObjects::CUSTOMER_FIELD])) {
            $entry->setStripeCustomerId($result[StripeObjects::CUSTOMER_FIELD]);
        }
    }

    protected function fillFailure(StripeApiCall $entry, ApiErrorException $exception, int|float $start): void
    {
        $entry
            ->setSuccess(false)
            ->setDurationMs(Duration::millisecondsSince($start))
            ->setHttpStatus($exception->getHttpStatus())
            ->setStripeRequestId($exception->getRequestId())
            ->setErrorType($exception->getError()?->type)
            ->setErrorCode($exception->getStripeCode())
            ->setErrorMessage($exception->getMessage());
    }

    protected function write(StripeApiCall $entry): void
    {
        try {
            $this->journal->write($entry);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'Stripe API call ' . $entry->getOperation() . ' could not be journaled: ' . $exception->getMessage(),
                $this->context($entry)
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(StripeApiCall $entry): array
    {
        return [
            'operation' => $entry->getOperation(),
            'success' => $entry->isSuccess(),
            'http_status' => $entry->getHttpStatus(),
            'request_id' => $entry->getStripeRequestId(),
            'resource_id' => $entry->getResourceId(),
            'stripe_customer_id' => $entry->getStripeCustomerId(),
            'idempotency_key' => $entry->getIdempotencyKey(),
            'error_type' => $entry->getErrorType(),
            'error_code' => $entry->getErrorCode(),
            'duration_ms' => $entry->getDurationMs(),
        ];
    }
}
