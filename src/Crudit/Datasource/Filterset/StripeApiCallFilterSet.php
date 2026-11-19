<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Datasource\Filterset;

use Lle\CruditBundle\Datasource\AbstractFilterSet;
use Lle\CruditBundle\Filter\FilterType\AbstractFilterType;
use Lle\CruditBundle\Filter\FilterType\BooleanFilterType;
use Lle\CruditBundle\Filter\FilterType\DateTimeFilterType;
use Lle\CruditBundle\Filter\FilterType\StringFilterType;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeApiCallFilterSet extends AbstractFilterSet
{
    /**
     * @return list<AbstractFilterType>
     */
    public function getFilters(): array
    {
        return [
            StringFilterType::new('operation'),
            BooleanFilterType::new('success'),
            StringFilterType::new('stripeCustomerId'),
            DateTimeFilterType::new('createdAt'),
        ];
    }
}
