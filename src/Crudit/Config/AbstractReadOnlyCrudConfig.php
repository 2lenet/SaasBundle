<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Crudit\Config;

use BackedEnum;
use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Crud\AbstractCrudConfig;
use Lle\CruditBundle\Datasource\DatasourceParams;
use Lle\CruditBundle\Dto\Action\ItemAction;
use Lle\CruditBundle\Dto\Action\ListAction;
use Lle\CruditBundle\Dto\Field\Field;
use Symfony\Component\HttpFoundation\Request;

/**
 * Screen in consultation only: the data comes from Stripe (plans, subscriptions) or is a journal. No add, edit or
 * delete action (the routes do not exist either, see AbstractReadOnlyCrudController).
 *
 * @internal not part of the public API of the bundle: may change without notice
 */
abstract class AbstractReadOnlyCrudConfig extends AbstractCrudConfig
{
    public const NAME_PREFIX = 'SAAS_';
    public const ROOT_ROUTE_PREFIX = 'lle_saas_crudit_';
    public const ENUM_TEMPLATE = '@LleSaas/crudit/field/enum.html.twig';
    /** Nested JSON (Stripe payloads): the Crudit json template prints flat arrays only. */
    public const JSON_TEMPLATE = '@LleSaas/crudit/field/json.html.twig';
    /** Translations of the screens: src/Resources/translations/LleSaasBundle.<locale>.yaml */
    public const TRANSLATION_DOMAIN = 'LleSaasBundle';
    /** Largest page of a list (the journals hold JSON payloads). */
    public const MAX_ITEMS = 200;
    public const SORT_ASC = 'ASC';
    public const SORT_DESC = 'DESC';
    /** Translation key prefix of the enum values ("enum.past_due"), see the enum template. */
    public const ENUM_TRANSLATION_PREFIX = 'enum.';

    /** Short name of the screen ("PLAN"): roles ROLE_SAAS_PLAN_INDEX, _SHOW, _EXPORT; route lle_saas_crudit_plan_*. */
    abstract protected function getScreen(): string;

    public function getName(): string
    {
        return self::NAME_PREFIX . $this->getScreen();
    }

    /** Route prefix of the controller; the list, to reference in a menu, is ROOT_ROUTE_PREFIX . screen . '_index'. */
    public function getRootRoute(): string
    {
        return self::ROOT_ROUTE_PREFIX . strtolower($this->getScreen());
    }

    /**
     * Choices of a filter on an enum column: translation key => stored value.
     *
     * @param list<BackedEnum> $cases
     *
     * @return array<string, int|string>
     */
    public static function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[self::ENUM_TRANSLATION_PREFIX . $case->value] = $case->value;
        }

        return $choices;
    }

    /**
     * Crudit puts the sort and page size of the query string in the query as they come: only the columns of the
     * list are sortable, in ASC / DESC order, and the page size is capped.
     */
    public function getDatasourceParams(Request $request, ?string $sessionKey = null): DatasourceParams
    {
        $params = parent::getDatasourceParams($request, $sessionKey);
        $params->setLimit(max(1, min($params->getLimit(), self::MAX_ITEMS)));
        $params->setSorts($this->allowedSorts($params->getSorts()));

        return $params;
    }

    /**
     * @param array<mixed> $sorts
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function allowedSorts(array $sorts): array
    {
        $sortable = array_column($this->getDefaultSort(), 0);
        foreach ($this->getFields(CrudConfigInterface::INDEX) as $field) {
            $sortable[] = $field->getName();
        }

        $allowed = [];
        foreach ($sorts as $sort) {
            $field = is_array($sort) ? ($sort[0] ?? null) : null;
            if (!is_string($field) || !in_array($field, $sortable, true)) {
                continue;
            }
            $order = is_string($sort[1] ?? null) ? strtoupper($sort[1]) : '';
            $allowed[] = [$field, $order === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC];
        }

        return $allowed === [] ? $this->getDefaultSort() : $allowed;
    }

    public function getTranslationDomain(): string
    {
        return self::TRANSLATION_DOMAIN;
    }

    /**
     * @return array<string, ListAction>
     */
    public function getListActions(): array
    {
        $actions = parent::getListActions();
        unset($actions[CrudConfigInterface::ACTION_ADD]);

        return $actions;
    }

    /**
     * @return array<string, ItemAction>
     */
    public function getItemActions(): array
    {
        $actions = parent::getItemActions();

        return [CrudConfigInterface::ACTION_SHOW => $actions[CrudConfigInterface::ACTION_SHOW]];
    }

    /**
     * @return array<string, ItemAction>
     */
    public function getShowActions(): array
    {
        $actions = parent::getShowActions();

        return [CrudConfigInterface::ACTION_LIST => $actions[CrudConfigInterface::ACTION_LIST]];
    }

    /**
     * @return list<Field>
     */
    abstract public function getFields(string $key): array;

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function getDefaultSort(): array
    {
        return [['id', 'DESC']];
    }
}
