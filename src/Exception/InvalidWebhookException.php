<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * The webhook request does not come from Stripe (bad or missing signature) or is not a Stripe event.
 */
class InvalidWebhookException extends RuntimeException
{
    public const MESSAGE = 'Invalid Stripe webhook: ';

    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE . $reason, 0, $previous);
    }
}
