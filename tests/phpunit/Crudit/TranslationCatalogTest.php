<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Crudit;

use Doctrine\ORM\EntityManagerInterface;
use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Filter\FilterState;
use Lle\CruditBundle\Filter\FilterType\ChoiceFilterType;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Crudit\Config\PlanCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeApiCallCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeEventCrudConfig;
use Lle\SaasBundle\Crudit\Config\SubscriptionCrudConfig;
use Lle\SaasBundle\Crudit\CrudMenu\SaasMenuProvider;
use Lle\SaasBundle\Crudit\Datasource\Filterset\StripeApiCallFilterSet;
use Lle\SaasBundle\Crudit\Datasource\Filterset\StripeEventFilterSet;
use Lle\SaasBundle\Crudit\Datasource\Filterset\SubscriptionFilterSet;
use Lle\SaasBundle\Crudit\Datasource\PlanDatasource;
use Lle\SaasBundle\Crudit\Datasource\StripeApiCallDatasource;
use Lle\SaasBundle\Crudit\Datasource\StripeEventDatasource;
use Lle\SaasBundle\Crudit\Datasource\SubscriptionDatasource;
use Lle\SaasBundle\Enum\PlanInterval;
use Lle\SaasBundle\Enum\StripeEventStatus;
use Lle\SaasBundle\Enum\SubscriptionStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every key displayed by the admin screens exists in each catalog of the bundle domain.
 */
class TranslationCatalogTest extends TestCase
{
    public const TRANSLATIONS = __DIR__ . '/../../../src/Resources/translations/';

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'fr' => ['fr'];
        yield 'en' => ['en'];
    }

    #[DataProvider('locales')]
    public function testEveryKeyIsTranslated(string $locale): void
    {
        $catalog = $this->catalog($locale);

        foreach ($this->usedKeys() as $key) {
            $this->assertArrayHasKey($key, $catalog, $locale . ': ' . $key);
        }
    }

    public function testCatalogsHaveTheSameKeys(): void
    {
        $this->assertSame(array_keys($this->catalog('fr')), array_keys($this->catalog('en')));
    }

    /**
     * @return list<string>
     */
    protected function usedKeys(): array
    {
        $keys = [];
        foreach ($this->configs() as $config) {
            $keys[] = (string) $config->getTitle('list');
            $keys[] = (string) $config->getTitle('show');
            foreach ([...$config->getListActions(), ...$config->getItemActions(), ...$config->getShowActions()] as $action) {
                $keys[] = $action->getLabel();
            }
            foreach ([CrudConfigInterface::INDEX, CrudConfigInterface::SHOW] as $page) {
                foreach ($config->getFields($page) as $field) {
                    $keys[] = $field->getLabel();
                }
            }
        }

        foreach ([new SubscriptionFilterSet(), new StripeEventFilterSet(), new StripeApiCallFilterSet()] as $filterSet) {
            foreach ($filterSet->getFilters() as $filter) {
                $keys[] = $filter->getLabel();
                if ($filter instanceof ChoiceFilterType) {
                    array_push($keys, ...array_map('strval', array_keys($filter->getChoices())));
                }
            }
        }

        $keys[] = SaasMenuProvider::LABEL;
        foreach (array_keys(SaasMenuProvider::ENTRIES) as $screen) {
            $keys[] = SaasMenuProvider::LABEL . '_' . strtolower($screen);
        }

        foreach ([...SubscriptionStatus::cases(), ...StripeEventStatus::cases(), ...PlanInterval::cases()] as $case) {
            $keys[] = AbstractReadOnlyCrudConfig::ENUM_TRANSLATION_PREFIX . $case->value;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<AbstractReadOnlyCrudConfig>
     */
    protected function configs(): array
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $filterState = $this->createStub(FilterState::class);

        return [
            new PlanCrudConfig(new PlanDatasource($entityManager, $filterState)),
            new SubscriptionCrudConfig(new SubscriptionDatasource($entityManager, $filterState)),
            new StripeEventCrudConfig(new StripeEventDatasource($entityManager, $filterState)),
            new StripeApiCallCrudConfig(new StripeApiCallDatasource($entityManager, $filterState)),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function catalog(string $locale): array
    {
        /** @var array<string, mixed> $tree */
        $tree = Yaml::parseFile(self::TRANSLATIONS . AbstractReadOnlyCrudConfig::TRANSLATION_DOMAIN . '.' . $locale . '.yaml');

        return $this->flatten($tree, '');
    }

    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, string>
     */
    protected function flatten(array $tree, string $prefix): array
    {
        $flat = [];
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $flat += $this->flatten($value, $prefix . $key . '.');
            } else {
                $flat[$prefix . $key] = (string) $value;
            }
        }

        return $flat;
    }
}
