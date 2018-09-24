<?php

namespace Doctrine\Tests\DBAL\Functional\Ticket;

use Doctrine\DBAL\Schema\Table;
use Doctrine\Tests\DbalFunctionalTestCase;

/**
 * @group DBAL-202
 */
class DBAL202Test extends DbalFunctionalTestCase
{
    protected function setUp()
    {
        parent::setUp();

        if ($this->_conn->getDatabasePlatform()->getName() !== 'oracle') {
            $this->markTestSkipped('OCI8 only test');
        }

        if ($this->_conn->getSchemaManager()->tablesExist('DBAL202')) {
            $this->_conn->exec('DELETE FROM DBAL202');
        } else {
            $table = new Table('DBAL202');
            $table->addColumn('id', 'integer');
            $table->setPrimaryKey(['id']);

            $this->_conn->getSchemaManager()->createTable($table);
        }
    }

    public function testStatementRollback()
    {
        $stmt = $this->_conn->prepare('INSERT INTO DBAL202 VALUES (8)');
        $this->_conn->beginTransaction();
        $stmt->execute();
        $this->_conn->rollBack();

        self::assertEquals(0, $this->_conn->query('SELECT COUNT(1) FROM DBAL202')->fetchColumn());
    }

    public function testStatementCommit()
    {
        $stmt = $this->_conn->prepare('INSERT INTO DBAL202 VALUES (8)');
        $this->_conn->beginTransaction();
        $stmt->execute();
        $this->_conn->commit();

        self::assertEquals(1, $this->_conn->query('SELECT COUNT(1) FROM DBAL202')->fetchColumn());
    }
}
