<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

/**
 * Entity manager of a class, reset when a failed transaction closed it. With several entity managers, the one
 * managing the class is reset (resetManager() without name would reset the default one).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class EntityManagerResetter
{
    public const NO_MANAGER = 'No Doctrine ORM entity manager for ';

    public function __construct(
        protected ManagerRegistry $registry,
    ) {
    }

    /**
     * @param class-string $class
     */
    public function openManagerFor(string $class): EntityManagerInterface
    {
        $entityManager = $this->managerFor($class);
        if ($entityManager->isOpen()) {
            return $entityManager;
        }

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) === $entityManager) {
                $this->registry->resetManager($name);
            }
        }

        return $this->managerFor($class);
    }

    /**
     * @param class-string $class
     */
    protected function managerFor(string $class): EntityManagerInterface
    {
        $entityManager = $this->registry->getManagerForClass($class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(self::NO_MANAGER . $class);
        }

        return $entityManager;
    }
}
