<?php

declare(strict_types=1);

namespace Lle\SaasBundle;

use Lle\SaasBundle\DependencyInjection\Compiler\ExcludeCustomerMappingPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class LleSaasBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ExcludeCustomerMappingPass());
    }
}
