<?php
/*
 * This file is part of the FODDBALClickHouse package -- Doctrine DBAL library
 * for ClickHouse (a column-oriented DBMS for OLAP <https://clickhouse.yandex/>)
 *
 * (c) FriendsOfDoctrine <https://github.com/FriendsOfDoctrine/>.
 *
 * For the full copyright and license inflormation, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOD\DBALClickHouse\Tests;

use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\Platforms\TrimMode;
use FOD\DBALClickHouse\Connection;
use PHPUnit\Framework\TestCase;

/**
 * ClickHouse DBAL test class. Testing Select operations in ClickHouse
 *
 * @author Nikolay Mitrofanov <mitrofanovnk@gmail.com>
 */
class SelectTest extends TestCase
{
    /** @var  Connection */
    protected $connection;

    public function setUp(): void
    {
        $this->connection = CreateConnectionTest::createConnection();

        $fromSchema = $this->connection->createSchemaManager()->createSchema();
        $toSchema = clone $fromSchema;

        $newTable = $toSchema->createTable('test_select_table');

        $newTable->addColumn('id', 'integer', ['unsigned' => true]);
        $newTable->addColumn('payload', 'string');
        $newTable->addColumn('hits', 'integer');
        $newTable->addOption('engine', 'Memory');
        $newTable->setPrimaryKey(['id']);

        foreach ($fromSchema->getMigrateToSql($toSchema, $this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->executeStatement("INSERT INTO test_select_table(id, payload, hits) VALUES (1, 'v1', 101), (2, 'v2', 202), (3, 'v3', 303), (4, 'v4', 404), (5, 'v4', 505), (6, '  t1   ', 606), (7, 'aat2aaa', 707)");
    }

    public function tearDown(): void
    {
        $this->connection->executeStatement('DROP TABLE test_select_table');
    }

    public function testFetchBothSelect()
    {
        $result = $this->connection->executeQuery('SELECT * FROM test_select_table WHERE id = 3');
        $results = $result->fetchAllAssociative();
        $this->assertEquals([['id' => 3, 'payload' => 'v3', 'hits' => 303]], $results);
    }

    public function testFetchAssocSelect()
    {
        $result = $this->connection->executeQuery('SELECT id, hits FROM test_select_table WHERE id IN (3, 4)');
        $results = [];
        foreach ($result->fetchAllAssociative() as $item) {
            $results[] = $item;
        }

        $this->assertEquals([['id' => 3, 'hits' => 303], ['id' => 4, 'hits' => 404]], $results);
    }

    public function testFetchNumSelect()
    {
        $result = $this->connection->executeQuery('SELECT max(hits) FROM test_select_table');
        $result = $result->fetchAssociative();
        $this->assertEquals(['max(hits)' => 707], $result);
    }

    public function testFetchAllBothSelect()
    {
        $result = $this->connection->executeQuery("SELECT * FROM test_select_table WHERE id IN (1, 3)");
        $result = $result->fetchAllAssociative();

        $this->assertEquals([
            [
                'id' => 1,
                'payload' => 'v1',
                'hits' => 101,
            ],
            [
                'id' => 3,
                'payload' => 'v3',
                'hits' => 303,
            ]
        ], $result);
    }

    public function testFetchAllNumSelect()
    {
        $result = $this->connection->executeQuery("SELECT AVG(hits) FROM test_select_table");
        $result = $result->fetchAllNumeric();

        $this->assertEquals([[404]], $result);
    }

    public function testFetchColumnValidOffsetSelect()
    {
        $result = $this->connection->executeQuery("SELECT payload, hits FROM test_select_table WHERE id > 1 ORDER BY id LIMIT 3");
        $results = [];
        while ($tmp = $result->fetchNumeric()) {
            $results[] = $tmp[1];
        }

        $this->assertEquals([202, 303, 404], $results);
    }

    public function testFetchColumnInvalidOffsetSelect()
    {
        $result = $this->connection->executeQuery("SELECT payload, hits FROM test_select_table WHERE id > 1 ORDER BY id");
        $results = [];
        while ($tmp = $result->fetchNumeric()) {
            $results[] = $tmp[0];
        }
        $this->assertEquals(['v2', 'v3', 'v4', 'v4', '  t1   ', 'aat2aaa'], $results);
    }

    public function testQueryBuilderSelect()
    {
        $qb = $this->connection->createQueryBuilder();
        $result = $qb
            ->select('payload, uniq(hits) as uniques')
            ->from('test_select_table')
            ->where('id > :id')
            ->setParameter('id', 2, \PDO::PARAM_INT)
            ->groupBy('payload')
            ->orderBy('payload')
            ->setMaxResults(2)
            ->executeQuery()
            ->fetchAllAssociative();

        $this->assertEquals([
            [
                'payload' => '  t1   ',
                'uniques' => '1',
            ],
            [
                'payload' => 'aat2aaa',
                'uniques' => '1',
            ]
        ], $result);
    }

    public function testDynamicParametersSelect()
    {
        $stmt = $this->connection->prepare('SELECT payload, AVG(hits) AS avg_hits FROM test_select_table WHERE id > :id GROUP BY payload ORDER BY avg_hits');

        $stmt->bindValue('id', 3, 'integer');
        $result = $stmt->executeQuery();
        $this->assertEquals([
            [
                'payload' => 'v4',
                'avg_hits' => 454.5,
            ],
            [
                'payload' => '  t1   ',
                'avg_hits' => 606,
            ],
            [
                'payload' => 'aat2aaa',
                'avg_hits' => 707,
            ]
        ], $result->fetchAllAssociative());
    }

    public function testColumnCount()
    {
        $stmt = $this->connection->prepare('SELECT * FROM test_select_table');
        $result = $stmt->executeQuery();

        $this->assertEquals(7, $result->columnCount());
    }

    public function testTrim()
    {
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT %s FROM test_select_table WHERE id = 6',
                $this->connection->getDatabasePlatform()->getTrimExpression('payload')
            )
        );
        $result = $stmt->executeQuery();

        $this->assertEquals('t1', $result->fetchOne());
    }

    public function testTrimLeft()
    {
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT %s FROM test_select_table WHERE id = 6',
                $this->connection->getDatabasePlatform()->getTrimExpression('payload', TrimMode::LEADING)
            )
        );
        $result = $stmt->executeQuery();

        $this->assertEquals('t1   ', $result->fetchOne());
    }

    public function testTrimRight()
    {
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT %s FROM test_select_table WHERE id = 6',
                $this->connection->getDatabasePlatform()->getTrimExpression('payload', TrimMode::TRAILING)
            )
        );
        $result = $stmt->executeQuery();

        $this->assertEquals('  t1', $result->fetchOne());
    }

    public function testTrimChar()
    {
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT %s FROM test_select_table WHERE id = 7',
                $this->connection->getDatabasePlatform()->getTrimExpression('payload', TrimMode::UNSPECIFIED, 'a')
            )
        );
        $result = $stmt->executeQuery();

        $this->assertEquals('t2', $result->fetchOne());
    }
}

