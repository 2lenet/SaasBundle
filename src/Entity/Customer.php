<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Contracts\CustomerInterface;
use Lle\SaasBundle\Repository\CustomerRepository;

/**
 * Default customer entity. Replaced, and excluded from the mapping, when "lle_saas.customer_class" is set.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'lle_saas_customer')]
class Customer implements CustomerInterface
{
    use CustomerTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    public function __construct()
    {
        $this->initializeCustomer();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
