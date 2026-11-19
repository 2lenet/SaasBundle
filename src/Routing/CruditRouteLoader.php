<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Routing;

use Lle\SaasBundle\DependencyInjection\LleSaasExtension;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouteCollection;

/**
 * Routes of the admin screens (type lle_saas_crudit, imported by the routes.yaml of the bundle): only when
 * crudit-bundle is installed, otherwise none.
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class CruditRouteLoader extends Loader
{
    public const TYPE = 'lle_saas_crudit';
    public const ALREADY_LOADED = 'The ' . self::TYPE . ' routes are already loaded';
    public const CONTROLLERS = __DIR__ . '/../Controller/Crudit/';
    public const ATTRIBUTE_TYPE = 'attribute';

    protected bool $loaded = false;

    public function __construct(
        #[Autowire(param: LleSaasExtension::PARAMETER_CRUDIT_ENABLED)]
        protected bool $enabled,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException(self::ALREADY_LOADED);
        }
        $this->loaded = true;

        if (!$this->enabled) {
            return new RouteCollection();
        }

        /** @var RouteCollection $routes */
        $routes = $this->import(self::CONTROLLERS, self::ATTRIBUTE_TYPE);

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
