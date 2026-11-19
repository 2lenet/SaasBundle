<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Controller\Crudit;

use Lle\SaasBundle\Crudit\Config\SubscriptionCrudConfig;
use Symfony\Component\Routing\Attribute\Route;

/** Routes lle_saas_crudit_subscription_index, _show, _export. */
/**
 * @internal not part of the public API of the bundle: may change without notice
 */
#[Route('/subscription')]
class SubscriptionController extends AbstractReadOnlyCrudController
{
    public function __construct(SubscriptionCrudConfig $config)
    {
        $this->config = $config;
    }
}
