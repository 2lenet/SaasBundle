<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Lle\SaasBundle\Contracts\SaasUserInterface;
use Lle\SaasBundle\Entity\SaasUserTrait;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application user as a consuming project would declare it (in real life: the Connect user).
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_user')]
class User implements UserInterface, SaasUserInterface
{
    use SaasUserTrait;

    public const ROLE_USER = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    /** @var non-empty-string */
    #[ORM\Column(type: 'string', length: 180)]
    protected string $email;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    protected array $roles = [];

    /**
     * @param non-empty-string $email
     */
    public function __construct(string $email)
    {
        $this->email = $email;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, self::ROLE_USER]));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }
}
