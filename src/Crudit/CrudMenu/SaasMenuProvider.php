<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\CrudMenu;

use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Contracts\MenuProviderInterface;
use Lle\CruditBundle\Dto\Icon;
use Lle\CruditBundle\Dto\Layout\LinkElement;
use Lle\CruditBundle\Dto\Path;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Crudit\Config\PlanCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeApiCallCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeEventCrudConfig;
use Lle\SaasBundle\Crudit\Config\SubscriptionCrudConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "SaaS" menu section (ROLE_SAAS_MENU) with the admin screens, each one shown with its ROLE_SAAS_<SCREEN>_INDEX.
 *
 * Crudit translates the menu labels in the "messages" domain: they are translated here, in the bundle domain
 * (a translated label is not a key of the application catalog, Crudit displays it as it is).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
class SaasMenuProvider implements MenuProviderInterface
{
    public const LABEL = 'menu.lle_saas';
    /** Role of the menu section (declared in the credential bundle by CruditPlatform, like the screen roles). */
    public const ROLE = 'ROLE_SAAS_MENU';
    public const ICON = 'credit-card';
    public const INDEX_SUFFIX = '_index';
    public const ENTRIES = [
        SubscriptionCrudConfig::SCREEN => 'list',
        PlanCrudConfig::SCREEN => 'tags',
        StripeEventCrudConfig::SCREEN => 'satellite-dish',
        StripeApiCallCrudConfig::SCREEN => 'exchange-alt',
    ];

    public function __construct(
        protected TranslatorInterface $translator,
    ) {
    }

    public function getMenuEntry(): iterable
    {
        $menu = LinkElement::new($this->trans(self::LABEL), null, Icon::new(self::ICON), self::ROLE);
        foreach (self::ENTRIES as $screen => $icon) {
            $menu->addChild(LinkElement::new(
                $this->trans(self::LABEL . '_' . strtolower($screen)),
                Path::new(AbstractReadOnlyCrudConfig::ROOT_ROUTE_PREFIX . strtolower($screen) . self::INDEX_SUFFIX),
                Icon::new($icon),
                'ROLE_' . AbstractReadOnlyCrudConfig::NAME_PREFIX . $screen . '_' . CrudConfigInterface::INDEX,
            ));
        }

        yield $menu;
    }

    protected function trans(string $key): string
    {
        return $this->translator->trans($key, [], AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN);
    }
}
