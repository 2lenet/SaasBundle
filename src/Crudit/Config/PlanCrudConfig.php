<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Config;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Dto\Field\Field;
use Lle\SaasBundle\Crudit\Datasource\PlanDatasource;

/**
 * Plans synchronized from Stripe (lle:saas:plan:sync, product.* / price.* webhooks): changed in Stripe, not here.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class PlanCrudConfig extends AbstractReadOnlyCrudConfig
{
    public const SCREEN = 'PLAN';

    public function __construct(PlanDatasource $datasource)
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
        $code = Field::new('code');
        $label = Field::new('label');
        $amount = Field::new('amount');
        $currency = Field::new('currency');
        $interval = Field::new('interval')->setTemplate(self::ENUM_TEMPLATE);
        $intervalCount = Field::new('intervalCount');
        $active = Field::new('active');
        $position = Field::new('position');

        return match ($key) {
            CrudConfigInterface::INDEX => [$position, $code, $label, $amount, $currency, $interval, $active],
            CrudConfigInterface::SHOW => [
                $code,
                $label,
                Field::new('description'),
                $amount,
                $currency,
                $interval,
                $intervalCount,
                $active,
                $position,
                Field::new('stripeProductId'),
                Field::new('stripePriceId'),
                Field::new('syncedAt'),
                Field::new('createdAt'),
            ],
            default => [],
        };
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function getDefaultSort(): array
    {
        return [['position', 'ASC'], ['code', 'ASC']];
    }
}
