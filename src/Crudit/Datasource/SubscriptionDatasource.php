<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Datasource;

use Doctrine\ORM\QueryBuilder;
use Lle\CruditBundle\Datasource\AbstractDoctrineDatasource;
use Lle\CruditBundle\Datasource\DatasourceParams;
use Lle\SaasBundle\Crudit\Datasource\Filterset\SubscriptionFilterSet;
use Lle\SaasBundle\Entity\Subscription;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class SubscriptionDatasource extends AbstractDoctrineDatasource
{
    public const CUSTOMER_ALIAS = 'saas_customer';
    public const PLAN_ALIAS = 'saas_plan';

    public function getClassName(): string
    {
        return Subscription::class;
    }

    #[Required]
    public function setFilterset(SubscriptionFilterSet $filterSet): void
    {
        $this->filterset = $filterSet;
    }

    /**
     * Customer and plan loaded with the list (displayed on each line).
     */
    public function buildQueryBuilder(?DatasourceParams $requestParams): QueryBuilder
    {
        return parent::buildQueryBuilder($requestParams)
            ->leftJoin('root.customer', self::CUSTOMER_ALIAS)
            ->addSelect(self::CUSTOMER_ALIAS)
            ->leftJoin('root.plan', self::PLAN_ALIAS)
            ->addSelect(self::PLAN_ALIAS);
    }
}
