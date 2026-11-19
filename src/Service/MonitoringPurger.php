<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Lle\SaasBundle\Dto\PurgeReport;
use Lle\SaasBundle\Repository\StripeApiCallRepository;
use Lle\SaasBundle\Repository\StripeEventRepository;

/**
 * Retention of the monitoring journals (StripeEvent, StripeApiCall).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class MonitoringPurger
{
    public const DEFAULT_DAYS = 90;
    public const MINIMUM_DAYS = 1;
    public const INVALID_DAYS = 'The retention must be at least ' . self::MINIMUM_DAYS . ' day.';

    public function __construct(
        protected StripeEventRepository $stripeEventRepository,
        protected StripeApiCallRepository $stripeApiCallRepository,
    ) {
    }

    /**
     * Deletes the entries older than $days days. Failed webhook events are kept unless $includeFailed.
     *
     * @throws InvalidArgumentException
     */
    public function purge(int $days, bool $includeFailed): PurgeReport
    {
        if ($days < self::MINIMUM_DAYS) {
            throw new InvalidArgumentException(self::INVALID_DAYS);
        }

        $before = new DateTimeImmutable('-' . $days . ' days');

        return new PurgeReport(
            $before,
            $this->stripeEventRepository->deleteReceivedBefore($before, $includeFailed),
            $this->stripeApiCallRepository->deleteCreatedBefore($before),
        );
    }
}
