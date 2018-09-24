<?php

namespace Doctrine\Tests\DBAL\Functional\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Schema;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use function array_map;
use function array_pop;
use function count;
use function strtolower;

class PostgreSqlSchemaManagerTest extends SchemaManagerFunctionalTestCase
{
    protected function tearDown()
    {
        parent::tearDown();

        if (! $this->_conn) {
            return;
        }

        $this->_conn->getConfiguration()->setFilterSchemaAssetsExpression(null);
    }

    /**
     * @group DBAL-177
     */
    public function testGetSearchPath()
    {
        $params = $this->_conn->getParams();

        $paths = $this->_sm->getSchemaSearchPaths();
        self::assertEquals([$params['user'], 'public'], $paths);
    }

    /**
     * @group DBAL-244
     */
    public function testGetSchemaNames()
    {
        $names = $this->_sm->getSchemaNames();

        self::assertInternalType('array', $names);
        self::assertNotEmpty($names);
        self::assertContains('public', $names, 'The public schema should be found.');
    }

    /**
     * @group DBAL-21
     */
    public function testSupportDomainTypeFallback()
    {
        $createDomainTypeSQL = 'CREATE DOMAIN MyMoney AS DECIMAL(18,2)';
        $this->_conn->exec($createDomainTypeSQL);

        $createTableSQL = 'CREATE TABLE domain_type_test (id INT PRIMARY KEY, value MyMoney)';
        $this->_conn->exec($createTableSQL);

        $table = $this->_conn->getSchemaManager()->listTableDetails('domain_type_test');
        self::assertInstanceOf('Doctrine\DBAL\Types\DecimalType', $table->getColumn('value')->getType());

        Type::addType('MyMoney', 'Doctrine\Tests\DBAL\Functional\Schema\MoneyType');
        $this->_conn->getDatabasePlatform()->registerDoctrineTypeMapping('MyMoney', 'MyMoney');

        $table = $this->_conn->getSchemaManager()->listTableDetails('domain_type_test');
        self::assertInstanceOf('Doctrine\Tests\DBAL\Functional\Schema\MoneyType', $table->getColumn('value')->getType());
    }

    /**
     * @group DBAL-37
     */
    public function testDetectsAutoIncrement()
    {
        $autoincTable = new Table('autoinc_table');
        $column       = $autoincTable->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $this->_sm->createTable($autoincTable);
        $autoincTable = $this->_sm->listTableDetails('autoinc_table');

        self::assertTrue($autoincTable->getColumn('id')->getAutoincrement());
    }

    /**
     * @group DBAL-37
     */
    public function testAlterTableAutoIncrementAdd()
    {
        $tableFrom = new Table('autoinc_table_add');
        $column    = $tableFrom->addColumn('id', 'integer');
        $this->_sm->createTable($tableFrom);
        $tableFrom = $this->_sm->listTableDetails('autoinc_table_add');
        self::assertFalse($tableFrom->getColumn('id')->getAutoincrement());

        $tableTo = new Table('autoinc_table_add');
        $column  = $tableTo->addColumn('id', 'integer');
        $column->setAutoincrement(true);

        $c    = new Comparator();
        $diff = $c->diffTable($tableFrom, $tableTo);
        $sql  = $this->_conn->getDatabasePlatform()->getAlterTableSQL($diff);
        self::assertEquals([
            'CREATE SEQUENCE autoinc_table_add_id_seq',
            "SELECT setval('autoinc_table_add_id_seq', (SELECT MAX(id) FROM autoinc_table_add))",
            "ALTER TABLE autoinc_table_add ALTER id SET DEFAULT nextval('autoinc_table_add_id_seq')",
        ], $sql);

        $this->_sm->alterTable($diff);
        $tableFinal = $this->_sm->listTableDetails('autoinc_table_add');
        self::assertTrue($tableFinal->getColumn('id')->getAutoincrement());
    }

    /**
     * @group DBAL-37
     */
    public function testAlterTableAutoIncrementDrop()
    {
        $tableFrom = new Table('autoinc_table_drop');
        $column    = $tableFrom->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $this->_sm->createTable($tableFrom);
        $tableFrom = $this->_sm->listTableDetails('autoinc_table_drop');
        self::assertTrue($tableFrom->getColumn('id')->getAutoincrement());

        $tableTo = new Table('autoinc_table_drop');
        $column  = $tableTo->addColumn('id', 'integer');

        $c    = new Comparator();
        $diff = $c->diffTable($tableFrom, $tableTo);
        self::assertInstanceOf('Doctrine\DBAL\Schema\TableDiff', $diff, 'There should be a difference and not false being returned from the table comparison');
        self::assertEquals(['ALTER TABLE autoinc_table_drop ALTER id DROP DEFAULT'], $this->_conn->getDatabasePlatform()->getAlterTableSQL($diff));

        $this->_sm->alterTable($diff);
        $tableFinal = $this->_sm->listTableDetails('autoinc_table_drop');
        self::assertFalse($tableFinal->getColumn('id')->getAutoincrement());
    }

    /**
     * @group DBAL-75
     */
    public function testTableWithSchema()
    {
        $this->_conn->exec('CREATE SCHEMA nested');

        $nestedRelatedTable = new Table('nested.schemarelated');
        $column             = $nestedRelatedTable->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $nestedRelatedTable->setPrimaryKey(['id']);

        $nestedSchemaTable = new Table('nested.schematable');
        $column            = $nestedSchemaTable->addColumn('id', 'integer');
        $column->setAutoincrement(true);
        $nestedSchemaTable->setPrimaryKey(['id']);
        $nestedSchemaTable->addUnnamedForeignKeyConstraint($nestedRelatedTable, ['id'], ['id']);

        $this->_sm->createTable($nestedRelatedTable);
        $this->_sm->createTable($nestedSchemaTable);

        $tables = $this->_sm->listTableNames();
        self::assertContains('nested.schematable', $tables, 'The table should be detected with its non-public schema.');

        $nestedSchemaTable = $this->_sm->listTableDetails('nested.schematable');
        self::assertTrue($nestedSchemaTable->hasColumn('id'));
        self::assertEquals(['id'], $nestedSchemaTable->getPrimaryKey()->getColumns());

        $relatedFks = $nestedSchemaTable->getForeignKeys();
        self::assertCount(1, $relatedFks);
        $relatedFk = array_pop($relatedFks);
        self::assertEquals('nested.schemarelated', $relatedFk->getForeignTableName());
    }

    /**
     * @group DBAL-91
     * @group DBAL-88
     */
    public function testReturnQuotedAssets()
    {
        $sql = 'create table dbal91_something ( id integer  CONSTRAINT id_something PRIMARY KEY NOT NULL  ,"table"   integer );';
        $this->_conn->exec($sql);

        $sql = 'ALTER TABLE dbal91_something ADD CONSTRAINT something_input FOREIGN KEY( "table" ) REFERENCES dbal91_something ON UPDATE CASCADE;';
        $this->_conn->exec($sql);

        $table = $this->_sm->listTableDetails('dbal91_something');

        self::assertEquals(
            [
                'CREATE TABLE dbal91_something (id INT NOT NULL, "table" INT DEFAULT NULL, PRIMARY KEY(id))',
                'CREATE INDEX IDX_A9401304ECA7352B ON dbal91_something ("table")',
            ],
            $this->_conn->getDatabasePlatform()->getCreateTableSQL($table)
        );
    }

    /**
     * @group DBAL-204
     */
    public function testFilterSchemaExpression()
    {
        $testTable = new Table('dbal204_test_prefix');
        $column    = $testTable->addColumn('id', 'integer');
        $this->_sm->createTable($testTable);
        $testTable = new Table('dbal204_without_prefix');
        $column    = $testTable->addColumn('id', 'integer');
        $this->_sm->createTable($testTable);

        $this->_conn->getConfiguration()->setFilterSchemaAssetsExpression('#^dbal204_#');
        $names = $this->_sm->listTableNames();
        self::assertCount(2, $names);

        $this->_conn->getConfiguration()->setFilterSchemaAssetsExpression('#^dbal204_test#');
        $names = $this->_sm->listTableNames();
        self::assertCount(1, $names);
    }

    public function testListForeignKeys()
    {
        if (! $this->_conn->getDatabasePlatform()->supportsForeignKeyConstraints()) {
            $this->markTestSkipped('Does not support foreign key constraints.');
        }

        $fkOptions   = ['SET NULL', 'SET DEFAULT', 'NO ACTION','CASCADE', 'RESTRICT'];
        $foreignKeys = [];
        $fkTable     = $this->getTestTable('test_create_fk1');
        for ($i = 0; $i < count($fkOptions); $i++) {
            $fkTable->addColumn("foreign_key_test$i", 'integer');
            $foreignKeys[] = new ForeignKeyConstraint(
                ["foreign_key_test$i"],
                'test_create_fk2',
                ['id'],
                "foreign_key_test_$i" . '_fk',
                ['onDelete' => $fkOptions[$i]]
            );
        }
        $this->_sm->dropAndCreateTable($fkTable);
        $this->createTestTable('test_create_fk2');

        foreach ($foreignKeys as $foreignKey) {
            $this->_sm->createForeignKey($foreignKey, 'test_create_fk1');
        }
        $fkeys = $this->_sm->listTableForeignKeys('test_create_fk1');
        self::assertEquals(count($foreignKeys), count($fkeys), "Table 'test_create_fk1' has to have " . count($foreignKeys) . ' foreign keys.');
        for ($i = 0; $i < count($fkeys); $i++) {
            self::assertEquals(["foreign_key_test$i"], array_map('strtolower', $fkeys[$i]->getLocalColumns()));
            self::assertEquals(['id'], array_map('strtolower', $fkeys[$i]->getForeignColumns()));
            self::assertEquals('test_create_fk2', strtolower($fkeys[0]->getForeignTableName()));
            if ($foreignKeys[$i]->getOption('onDelete') === 'NO ACTION') {
                self::assertFalse($fkeys[$i]->hasOption('onDelete'), 'Unexpected option: ' . $fkeys[$i]->getOption('onDelete'));
            } else {
                self::assertEquals($foreignKeys[$i]->getOption('onDelete'), $fkeys[$i]->getOption('onDelete'));
            }
        }
    }

    /**
     * @group DBAL-511
     */
    public function testDefaultValueCharacterVarying()
    {
        $testTable = new Table('dbal511_default');
        $testTable->addColumn('id', 'integer');
        $testTable->addColumn('def', 'string', ['default' => 'foo']);
        $testTable->setPrimaryKey(['id']);

        $this->_sm->createTable($testTable);

        $databaseTable = $this->_sm->listTableDetails($testTable->getName());

        self::assertEquals('foo', $databaseTable->getColumn('def')->getDefault());
    }

    /**
     * @group DDC-2843
     */
    public function testBooleanDefault()
    {
        $table = new Table('ddc2843_bools');
        $table->addColumn('id', 'integer');
        $table->addColumn('checked', 'boolean', ['default' => false]);

        $this->_sm->createTable($table);

        $databaseTable = $this->_sm->listTableDetails($table->getName());

        $c    = new Comparator();
        $diff = $c->diffTable($table, $databaseTable);

        self::assertFalse($diff);
    }

    public function testListTableWithBinary()
    {
        $tableName = 'test_binary_table';

        $table = new Table($tableName);
        $table->addColumn('id', 'integer');
        $table->addColumn('column_varbinary', 'binary', []);
        $table->addColumn('column_binary', 'binary', ['fixed' => true]);
        $table->setPrimaryKey(['id']);

        $this->_sm->createTable($table);

        $table = $this->_sm->listTableDetails($tableName);

        self::assertInstanceOf('Doctrine\DBAL\Types\BlobType', $table->getColumn('column_varbinary')->getType());
        self::assertFalse($table->getColumn('column_varbinary')->getFixed());

        self::assertInstanceOf('Doctrine\DBAL\Types\BlobType', $table->getColumn('column_binary')->getType());
        self::assertFalse($table->getColumn('column_binary')->getFixed());
    }

    public function testListQuotedTable()
    {
        $offlineTable = new Schema\Table('user');
        $offlineTable->addColumn('id', 'integer');
        $offlineTable->addColumn('username', 'string');
        $offlineTable->addColumn('fk', 'integer');
        $offlineTable->setPrimaryKey(['id']);
        $offlineTable->addForeignKeyConstraint($offlineTable, ['fk'], ['id']);

        $this->_sm->dropAndCreateTable($offlineTable);

        $onlineTable = $this->_sm->listTableDetails('"user"');

        $comparator = new Schema\Comparator();

        self::assertFalse($comparator->diffTable($offlineTable, $onlineTable));
    }

    public function testListTablesExcludesViews()
    {
        $this->createTestTable('list_tables_excludes_views');

        $name = 'list_tables_excludes_views_test_view';
        $sql  = 'SELECT * from list_tables_excludes_views';

        $view = new Schema\View($name, $sql);

        $this->_sm->dropAndCreateView($view);

        $tables = $this->_sm->listTables();

        $foundTable = false;
        foreach ($tables as $table) {
            self::assertInstanceOf('Doctrine\DBAL\Schema\Table', $table, 'No Table instance was found in tables array.');
            if (strtolower($table->getName()) !== 'list_tables_excludes_views_test_view') {
                continue;
            }

            $foundTable = true;
        }

        self::assertFalse($foundTable, 'View "list_tables_excludes_views_test_view" must not be found in table list');
    }

    /**
     * @group DBAL-1033
     */
    public function testPartialIndexes()
    {
        $offlineTable = new Schema\Table('person');
        $offlineTable->addColumn('id', 'integer');
        $offlineTable->addColumn('name', 'string');
        $offlineTable->addColumn('email', 'string');
        $offlineTable->addUniqueIndex(['id', 'name'], 'simple_partial_index', ['where' => '(id IS NULL)']);

        $this->_sm->dropAndCreateTable($offlineTable);

        $onlineTable = $this->_sm->listTableDetails('person');

        $comparator = new Schema\Comparator();

        self::assertFalse($comparator->diffTable($offlineTable, $onlineTable));
        self::assertTrue($onlineTable->hasIndex('simple_partial_index'));
        self::assertTrue($onlineTable->getIndex('simple_partial_index')->hasOption('where'));
        self::assertSame('(id IS NULL)', $onlineTable->getIndex('simple_partial_index')->getOption('where'));
    }

    /**
     * @dataProvider jsonbColumnTypeProvider
     */
    public function testJsonbColumn(string $type) : void
    {
        if (! $this->_sm->getDatabasePlatform() instanceof PostgreSQL94Platform) {
            $this->markTestSkipped('Requires PostgresSQL 9.4+');
            return;
        }

        $table = new Schema\Table('test_jsonb');
        $table->addColumn('foo', $type)->setPlatformOption('jsonb', true);
        $this->_sm->dropAndCreateTable($table);

        /** @var Schema\Column[] $columns */
        $columns = $this->_sm->listTableColumns('test_jsonb');

        self::assertSame($type, $columns['foo']->getType()->getName());
        self::assertTrue(true, $columns['foo']->getPlatformOption('jsonb'));
    }

    public function jsonbColumnTypeProvider() : array
    {
        return [
            [Type::JSON],
            [Type::JSON_ARRAY],
        ];
    }

    /**
     * @group DBAL-2427
     */
    public function testListNegativeColumnDefaultValue()
    {
        $table = new Schema\Table('test_default_negative');
        $table->addColumn('col_smallint', 'smallint', ['default' => -1]);
        $table->addColumn('col_integer', 'integer', ['default' => -1]);
        $table->addColumn('col_bigint', 'bigint', ['default' => -1]);
        $table->addColumn('col_float', 'float', ['default' => -1.1]);
        $table->addColumn('col_decimal', 'decimal', ['default' => -1.1]);
        $table->addColumn('col_string', 'string', ['default' => '(-1)']);

        $this->_sm->dropAndCreateTable($table);

        $columns = $this->_sm->listTableColumns('test_default_negative');

        self::assertEquals(-1, $columns['col_smallint']->getDefault());
        self::assertEquals(-1, $columns['col_integer']->getDefault());
        self::assertEquals(-1, $columns['col_bigint']->getDefault());
        self::assertEquals(-1.1, $columns['col_float']->getDefault());
        self::assertEquals(-1.1, $columns['col_decimal']->getDefault());
        self::assertEquals('(-1)', $columns['col_string']->getDefault());
    }

    public static function serialTypes() : array
    {
        return [
            ['integer'],
            ['bigint'],
        ];
    }

    /**
     * @dataProvider serialTypes
     * @group 2906
     */
    public function testAutoIncrementCreatesSerialDataTypesWithoutADefaultValue(string $type) : void
    {
        $tableName = "test_serial_type_$type";

        $table = new Schema\Table($tableName);
        $table->addColumn('id', $type, ['autoincrement' => true, 'notnull' => false]);

        $this->_sm->dropAndCreateTable($table);

        $columns = $this->_sm->listTableColumns($tableName);

        self::assertNull($columns['id']->getDefault());
    }

    /**
     * @dataProvider serialTypes
     * @group 2906
     */
    public function testAutoIncrementCreatesSerialDataTypesWithoutADefaultValueEvenWhenDefaultIsSet(string $type) : void
    {
        $tableName = "test_serial_type_with_default_$type";

        $table = new Schema\Table($tableName);
        $table->addColumn('id', $type, ['autoincrement' => true, 'notnull' => false, 'default' => 1]);

        $this->_sm->dropAndCreateTable($table);

        $columns = $this->_sm->listTableColumns($tableName);

        self::assertNull($columns['id']->getDefault());
    }

    /**
     * @group 2916
     * @dataProvider autoIncrementTypeMigrations
     */
    public function testAlterTableAutoIncrementIntToBigInt(string $from, string $to, string $expected) : void
    {
        $tableFrom = new Table('autoinc_type_modification');
        $column    = $tableFrom->addColumn('id', $from);
        $column->setAutoincrement(true);
        $this->_sm->dropAndCreateTable($tableFrom);
        $tableFrom = $this->_sm->listTableDetails('autoinc_type_modification');
        self::assertTrue($tableFrom->getColumn('id')->getAutoincrement());

        $tableTo = new Table('autoinc_type_modification');
        $column  = $tableTo->addColumn('id', $to);
        $column->setAutoincrement(true);

        $c    = new Comparator();
        $diff = $c->diffTable($tableFrom, $tableTo);
        self::assertInstanceOf(TableDiff::class, $diff, 'There should be a difference and not false being returned from the table comparison');
        self::assertSame(['ALTER TABLE autoinc_type_modification ALTER id TYPE ' . $expected], $this->_conn->getDatabasePlatform()->getAlterTableSQL($diff));

        $this->_sm->alterTable($diff);
        $tableFinal = $this->_sm->listTableDetails('autoinc_type_modification');
        self::assertTrue($tableFinal->getColumn('id')->getAutoincrement());
    }

    public function autoIncrementTypeMigrations() : array
    {
        return [
            'int->bigint' => ['integer', 'bigint', 'BIGINT'],
            'bigint->int' => ['bigint', 'integer', 'INT'],
        ];
    }
}

class MoneyType extends Type
{
    public function getName()
    {
        return 'MyMoney';
    }

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform)
    {
        return 'MyMoney';
    }
}
