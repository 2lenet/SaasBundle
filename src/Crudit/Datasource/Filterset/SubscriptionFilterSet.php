<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Datasource\Filterset;

use Lle\CruditBundle\Datasource\AbstractFilterSet;
use Lle\CruditBundle\Filter\FilterType\AbstractFilterType;
use Lle\CruditBundle\Filter\FilterType\ChoiceFilterType;
use Lle\CruditBundle\Filter\FilterType\StringFilterType;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Enum\SubscriptionStatus;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class SubscriptionFilterSet extends AbstractFilterSet
{
    /**
     * @return list<AbstractFilterType>
     */
    public function getFilters(): array
    {
        return [
            ChoiceFilterType::new('status', AbstractReadOnlyCrudConfig::enumChoices(SubscriptionStatus::cases()), true),
            StringFilterType::new('stripeSubscriptionId'),
        ];
    }
}
