<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller;

use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Exception\InvalidWebhookException;
use Lle\SaasBundle\Webhook\WebhookProcessor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stripe webhook endpoint. Public: the request is authenticated by its signature (lle_saas.stripe.webhook_secret).
 * Answers 500 when the processing failed, 409 while another delivery of the event is being processed: in both cases
 * Stripe sends the event again.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class WebhookController
{
    public const ROUTE = 'lle_saas_webhook';
    public const SIGNATURE_HEADER = 'Stripe-Signature';
    public const KEY_STATUS = 'status';
    public const KEY_ERROR = 'error';
    /** Fixed answer: the message of the SDK describes the expected secret (length, last characters). */
    public const ERROR_INVALID_SIGNATURE = 'invalid_signature';

    public function __construct(
        protected WebhookProcessor $webhookProcessor,
    ) {
    }

    #[Route('/webhook', name: self::ROUTE, methods: [Request::METHOD_POST])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $journal = $this->webhookProcessor->receive(
                $request->getContent(),
                (string) $request->headers->get(self::SIGNATURE_HEADER),
            );
        } catch (InvalidWebhookException) {
            return new JsonResponse([self::KEY_ERROR => self::ERROR_INVALID_SIGNATURE], Response::HTTP_BAD_REQUEST);
        }

        $status = $journal->getStatus();

        return new JsonResponse([self::KEY_STATUS => $status->value], match ($status) {
            StripeEventStatus::Failed => Response::HTTP_INTERNAL_SERVER_ERROR,
            StripeEventStatus::Processing => Response::HTTP_CONFLICT,
            default => Response::HTTP_OK,
        });
    }
}
