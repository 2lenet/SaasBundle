<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Enum;

/**
 * Role of a user inside its customer account (not a Symfony security role).
 */
enum CustomerRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
