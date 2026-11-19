<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller\Crudit;

use Lle\SaasBundle\Crudit\Config\StripeEventCrudConfig;
use Symfony\Component\Routing\Attribute\Route;

/** Routes lle_saas_crudit_stripeevent_index, _show, _export. */
/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[Route('/stripe-event')]
class StripeEventController extends AbstractReadOnlyCrudController
{
    public function __construct(StripeEventCrudConfig $config)
    {
        $this->config = $config;
    }
}
