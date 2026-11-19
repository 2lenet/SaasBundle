<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller\Crudit;

use Lle\SaasBundle\Crudit\Config\PlanCrudConfig;
use Symfony\Component\Routing\Attribute\Route;

/** Routes lle_saas_crudit_plan_index, _show, _export. */
/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[Route('/plan')]
class PlanController extends AbstractReadOnlyCrudController
{
    public function __construct(PlanCrudConfig $config)
    {
        $this->config = $config;
    }
}
