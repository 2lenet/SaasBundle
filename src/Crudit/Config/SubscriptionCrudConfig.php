<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Config;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Dto\Field\Field;
use Lle\SaasBundle\Crudit\Datasource\SubscriptionDatasource;

/**
 * Local copy of the Stripe subscriptions (webhooks, lle:saas:reconcile): changed in Stripe, not here.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class SubscriptionCrudConfig extends AbstractReadOnlyCrudConfig
{
    public const SCREEN = 'SUBSCRIPTION';

    public function __construct(SubscriptionDatasource $datasource)
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
        $customer = Field::new('customer.name')->setLabel('field.customer');
        $plan = Field::new('plan.label')->setLabel('field.plan');
        $status = Field::new('status')->setTemplate(self::ENUM_TEMPLATE);
        $quantity = Field::new('quantity');
        $periodEnd = Field::new('currentPeriodEnd');
        $cancelAtPeriodEnd = Field::new('cancelAtPeriodEnd');
        $stripeSubscriptionId = Field::new('stripeSubscriptionId');

        return match ($key) {
            CrudConfigInterface::INDEX => [$customer, $plan, $status, $quantity, $periodEnd, $cancelAtPeriodEnd, $stripeSubscriptionId],
            CrudConfigInterface::SHOW => [
                $customer,
                $plan,
                $status,
                $quantity,
                Field::new('currentPeriodStart'),
                $periodEnd,
                $cancelAtPeriodEnd,
                Field::new('cancelAt'),
                Field::new('canceledAt'),
                Field::new('endedAt'),
                $stripeSubscriptionId,
                Field::new('createdAt'),
                Field::new('updatedAt'),
            ],
            default => [],
        };
    }
}
