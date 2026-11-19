<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Datasource;

use Lle\CruditBundle\Datasource\AbstractDoctrineDatasource;
use Lle\SaasBundle\Entity\Plan;

/**
 * @internal not part of the public API of the bundle: may change without notice
 */
class PlanDatasource extends AbstractDoctrineDatasource
{
    public function getClassName(): string
    {
        return Plan::class;
    }
}
