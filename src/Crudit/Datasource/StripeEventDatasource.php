<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Datasource;

use Lle\CruditBundle\Datasource\AbstractDoctrineDatasource;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Crudit\Datasource\Filterset\StripeEventFilterSet;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeEventDatasource extends AbstractDoctrineDatasource
{
    public function getClassName(): string
    {
        return StripeEvent::class;
    }

    #[Required]
    public function setFilterset(StripeEventFilterSet $filterSet): void
    {
        $this->filterset = $filterSet;
    }
}
