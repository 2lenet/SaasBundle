<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Crudit;

use Doctrine\ORM\EntityManagerInterface;
use Lle\CruditBundle\Contracts\CrudConfigInterface;
use Lle\CruditBundle\Filter\FilterState;
use Lle\SaasBundle\Crudit\Config\AbstractReadOnlyCrudConfig;
use Lle\SaasBundle\Crudit\Config\PlanCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeApiCallCrudConfig;
use Lle\SaasBundle\Crudit\Config\StripeEventCrudConfig;
use Lle\SaasBundle\Crudit\Config\SubscriptionCrudConfig;
use Lle\SaasBundle\Crudit\Datasource\PlanDatasource;
use Lle\SaasBundle\Crudit\Datasource\StripeApiCallDatasource;
use Lle\SaasBundle\Crudit\Datasource\StripeEventDatasource;
use Lle\SaasBundle\Crudit\Datasource\SubscriptionDatasource;
use Lle\SaasBundle\Entity\Plan;
use Lle\SaasBundle\Entity\StripeApiCall;
use Lle\SaasBundle\Entity\StripeEvent;
use Lle\SaasBundle\Entity\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ReadOnlyCrudConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<AbstractReadOnlyCrudConfig>, class-string, class-string, string}>
     */
    public static function configs(): iterable
    {
        yield 'plan' => [PlanCrudConfig::class, PlanDatasource::class, Plan::class, 'SAAS_PLAN'];
        yield 'subscription' => [SubscriptionCrudConfig::class, SubscriptionDatasource::class, Subscription::class, 'SAAS_SUBSCRIPTION'];
        yield 'stripe event' => [StripeEventCrudConfig::class, StripeEventDatasource::class, StripeEvent::class, 'SAAS_STRIPEEVENT'];
        yield 'stripe api call' => [
            StripeApiCallCrudConfig::class,
            StripeApiCallDatasource::class,
            StripeApiCall::class,
            'SAAS_STRIPEAPICALL',
        ];
    }

    /**
     * @param class-string<AbstractReadOnlyCrudConfig> $configClass
     * @param class-string $datasourceClass
     */
    #[DataProvider('configs')]
    public function testNameAndRootRoute(string $configClass, string $datasourceClass, string $entityClass, string $name): void
    {
        $config = $this->config($configClass, $datasourceClass);

        $this->assertSame($name, $config->getName());
        $this->assertSame('lle_saas_crudit_' . strtolower(substr($name, strlen(AbstractReadOnlyCrudConfig::NAME_PREFIX))), $config->getRootRoute());
    }

    /**
     * @param class-string<AbstractReadOnlyCrudConfig> $configClass
     * @param class-string $datasourceClass
     */
    #[DataProvider('configs')]
    public function testOffersNoChangeAction(string $configClass, string $datasourceClass, string $entityClass, string $name): void
    {
        $config = $this->config($configClass, $datasourceClass);

        $this->assertSame(
            [CrudConfigInterface::ACTION_LIST, CrudConfigInterface::ACTION_EXPORT],
            array_keys($config->getListActions()),
        );
        $this->assertSame([CrudConfigInterface::ACTION_SHOW], array_keys($config->getItemActions()));
        $this->assertSame([CrudConfigInterface::ACTION_LIST], array_keys($config->getShowActions()));
        $this->assertSame('ROLE_' . $name . '_EXPORT', $config->getListActions()[CrudConfigInterface::ACTION_EXPORT]->getRole());
    }

    /**
     * @param class-string<AbstractReadOnlyCrudConfig> $configClass
     * @param class-string $datasourceClass
     * @param class-string $entityClass
     */
    #[DataProvider('configs')]
    public function testFieldsExistOnTheEntity(string $configClass, string $datasourceClass, string $entityClass, string $name): void
    {
        $config = $this->config($configClass, $datasourceClass);
        $entity = new ReflectionClass($entityClass);

        foreach ([CrudConfigInterface::INDEX, CrudConfigInterface::SHOW] as $key) {
            $fields = $config->getFields($key);
            $this->assertNotEmpty($fields);
            foreach ($fields as $field) {
                $property = explode('.', $field->getName())[0];
                $this->assertTrue($entity->hasProperty($property), $entityClass . '::$' . $property);
            }
        }
    }

    /**
     * @return iterable<string, array{class-string<AbstractReadOnlyCrudConfig>, class-string, string}>
     */
    public static function payloads(): iterable
    {
        yield 'stripe event' => [StripeEventCrudConfig::class, StripeEventDatasource::class, 'payload'];
        yield 'stripe api call' => [StripeApiCallCrudConfig::class, StripeApiCallDatasource::class, 'requestPayload'];
    }

    /**
     * @param class-string<AbstractReadOnlyCrudConfig> $configClass
     * @param class-string $datasourceClass
     */
    #[DataProvider('payloads')]
    public function testJournaledPayloadIsShownAsJson(string $configClass, string $datasourceClass, string $fieldName): void
    {
        $config = $this->config($configClass, $datasourceClass);

        $templates = [];
        foreach ($config->getFields(CrudConfigInterface::SHOW) as $field) {
            $templates[$field->getName()] = $field->getTemplate();
        }

        $this->assertSame(AbstractReadOnlyCrudConfig::JSON_TEMPLATE, $templates[$fieldName] ?? null);
    }

    public function testSortAndPageSizeOfTheQueryStringAreRestricted(): void
    {
        $config = $this->config(StripeEventCrudConfig::class, StripeEventDatasource::class);
        $request = $this->request([
            'saas_stripeevent_sort' => 'COALESCE((SELECT u.password FROM App\\Entity\\User u), 1)',
            'saas_stripeevent_sort_order' => 'ASC, root.id',
            'saas_stripeevent_limit' => '10000000',
        ]);

        $params = $config->getDatasourceParams($request);

        $this->assertSame($config->getDefaultSort(), $params->getSorts());
        $this->assertSame(AbstractReadOnlyCrudConfig::MAX_ITEMS, $params->getLimit());
    }

    public function testAListColumnCanBeSorted(): void
    {
        $config = $this->config(StripeEventCrudConfig::class, StripeEventDatasource::class);
        $request = $this->request(['saas_stripeevent_sort' => 'type', 'saas_stripeevent_sort_order' => 'desc']);

        $params = $config->getDatasourceParams($request);

        $this->assertSame([['type', 'DESC']], $params->getSorts());
    }

    /**
     * @param array<string, string> $query
     */
    protected function request(array $query): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    /**
     * @param class-string<AbstractReadOnlyCrudConfig> $configClass
     * @param class-string $datasourceClass
     */
    protected function config(string $configClass, string $datasourceClass): AbstractReadOnlyCrudConfig
    {
        $datasource = new $datasourceClass($this->createStub(EntityManagerInterface::class), $this->createStub(FilterState::class));

        return new $configClass($datasource);
    }
}
