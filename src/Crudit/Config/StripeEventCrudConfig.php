<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Config;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Dto\Field\Field;
use Lle\SaasBundle\Crudit\Datasource\StripeEventDatasource;

/**
 * Journal of the Stripe webhook events (failed ones are replayed with lle:saas:webhook:replay).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeEventCrudConfig extends AbstractReadOnlyCrudConfig
{
    public const SCREEN = 'STRIPEEVENT';

    public function __construct(StripeEventDatasource $datasource)
    {
        $this->datasource = $datasource;
    }

    protected function getScreen(): string
    {
        return self::SCREEN;
    }

    /**
     * @return list<Field>
     */
    public function getFields(string $key): array
    {
        $receivedAt = Field::new('receivedAt');
        $type = Field::new('type');
        $status = Field::new('status')->setTemplate(self::ENUM_TEMPLATE);
        $attempts = Field::new('attempts');
        $objectId = Field::new('objectId');
        $stripeCustomerId = Field::new('stripeCustomerId');
        $durationMs = Field::new('durationMs');
        $error = Field::new('error');

        return match ($key) {
            CrudConfigInterface::INDEX => [$receivedAt, $type, $status, $attempts, $objectId, $stripeCustomerId, $durationMs],
            CrudConfigInterface::SHOW => [
                Field::new('stripeEventId'),
                $type,
                $status,
                $error,
                $attempts,
                $receivedAt,
                Field::new('stripeCreatedAt'),
                Field::new('claimedAt'),
                Field::new('processedAt'),
                $durationMs,
                Field::new('objectType'),
                $objectId,
                $stripeCustomerId,
                Field::new('livemode'),
                Field::new('apiVersion'),
                Field::new('payload')->setTemplate(self::JSON_TEMPLATE),
            ],
            default => [],
        };
    }
}
