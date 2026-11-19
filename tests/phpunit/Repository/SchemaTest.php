<?php

declare(strict_types=1);

namespace Lle\SaasBundle\Tests\Repository;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\ORM\Tools\SchemaValidator;

class SchemaTest extends DatabaseTestCase
{
    public const EXPECTED_TABLES = [
        'lle_saas_customer',
        'lle_saas_plan',
        'lle_saas_subscription',
        'lle_saas_stripe_api_call',
        'lle_saas_stripe_event',
    ];

    public function testMappingIsValid(): void
    {
        $validator = new SchemaValidator($this->entityManager);

        $this->assertSame([], $validator->validateMapping());
    }

    public function testBundleTablesAreCreated(): void
    {
        $tables = array_map(
            static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
            $this->entityManager->getConnection()->createSchemaManager()->introspectTableNames()
        );

        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertContains($table, $tables);
        }
    }

    public function testUserTableReceivesCustomerColumns(): void
    {
        $columns = $this->entityManager->getConnection()->createSchemaManager()
            ->introspectTableColumnsByUnquotedName('test_user');
        $columnNames = array_map(static fn (Column $column): string => $column->getObjectName()->getIdentifier()->getValue(), $columns);

        $this->assertContains('customer_id', $columnNames);
        $this->assertContains('customer_role', $columnNames);
    }
}
