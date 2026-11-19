<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Config;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Dto\Field\Field;
use Lle\SaasBundle\Crudit\Datasource\StripeApiCallDatasource;

/**
 * Journal of the calls made to the Stripe API.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeApiCallCrudConfig extends AbstractReadOnlyCrudConfig
{
    public const SCREEN = 'STRIPEAPICALL';

    public function __construct(StripeApiCallDatasource $datasource)
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
        $createdAt = Field::new('createdAt');
        $operation = Field::new('operation');
        $success = Field::new('success');
        $httpStatus = Field::new('httpStatus');
        $resourceId = Field::new('resourceId');
        $stripeCustomerId = Field::new('stripeCustomerId');
        $durationMs = Field::new('durationMs');
        $errorMessage = Field::new('errorMessage');

        return match ($key) {
            CrudConfigInterface::INDEX => [
                $createdAt,
                $operation,
                $success,
                $httpStatus,
                $resourceId,
                $stripeCustomerId,
                $durationMs,
                $errorMessage,
            ],
            CrudConfigInterface::SHOW => [
                $operation,
                $success,
                $httpStatus,
                $createdAt,
                $durationMs,
                $resourceId,
                $stripeCustomerId,
                Field::new('stripeRequestId'),
                Field::new('idempotencyKey'),
                Field::new('errorType'),
                Field::new('errorCode'),
                $errorMessage,
                Field::new('requestPayload')->setTemplate(self::JSON_TEMPLATE),
            ],
            default => [],
        };
    }
}
