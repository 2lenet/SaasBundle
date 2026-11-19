<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller\Crudit;

use Lle\SaasBundle\Crudit\Config\StripeApiCallCrudConfig;
use Symfony\Component\Routing\Attribute\Route;

/** Routes lle_saas_crudit_stripeapicall_index, _show, _export. */
/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[Route('/stripe-api-call')]
class StripeApiCallController extends AbstractReadOnlyCrudController
{
    public function __construct(StripeApiCallCrudConfig $config)
    {
        $this->config = $config;
    }
}
