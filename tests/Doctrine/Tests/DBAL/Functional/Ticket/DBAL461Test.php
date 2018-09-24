<?php

namespace Doctrine\Tests\DBAL\Functional\Ticket;

use Doctrine\DBAL\Schema\SQLServerSchemaManager;
use Doctrine\DBAL\Types\DecimalType;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @group DBAL-461
 */
class DBAL461Test extends TestCase
{
    public function testIssue()
    {
        $conn     = $this->createMock('Doctrine\DBAL\Connection');
        $platform = $this->getMockForAbstractClass('Doctrine\DBAL\Platforms\AbstractPlatform');
        $platform->registerDoctrineTypeMapping('numeric', 'decimal');

        $schemaManager = new SQLServerSchemaManager($conn, $platform);

        $reflectionMethod = new ReflectionMethod($schemaManager, '_getPortableTableColumnDefinition');
        $reflectionMethod->setAccessible(true);
        $column = $reflectionMethod->invoke($schemaManager, [
            'type' => 'numeric(18,0)',
            'length' => null,
            'default' => null,
            'notnull' => false,
            'scale' => 18,
            'precision' => 0,
            'autoincrement' => false,
            'collation' => 'foo',
            'comment' => null,
        ]);

        $this->assertInstanceOf(DecimalType::class, $column->getType());
    }
}
