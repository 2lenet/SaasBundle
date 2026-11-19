<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Datasource;

use Lle\CruditBundle\Datasource\AbstractDoctrineDatasource;
use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Crudit\Datasource\Filterset\StripeApiCallFilterSet;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class StripeApiCallDatasource extends AbstractDoctrineDatasource
{
    public function getClassName(): string
    {
        return StripeApiCall::class;
    }

    #[Required]
    public function setFilterset(StripeApiCallFilterSet $filterSet): void
    {
        $this->filterset = $filterSet;
    }
}
