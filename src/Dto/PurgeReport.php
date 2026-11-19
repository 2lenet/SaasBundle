<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Dto;

use DateTimeImmutable;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class PurgeReport
{
    public function __construct(
        protected DateTimeImmutable $before,
        protected int $deletedEvents,
        protected int $deletedApiCalls,
    ) {
    }

    public function getBefore(): DateTimeImmutable
    {
        return $this->before;
    }

    public function getDeletedEvents(): int
    {
        return $this->deletedEvents;
    }

    public function getDeletedApiCalls(): int
    {
        return $this->deletedApiCalls;
    }
}
