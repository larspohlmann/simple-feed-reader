<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * The failure transport's table, which `auto_setup` creates only on the first failed delivery and doctrine.yaml's
 * schema_filter hides from the test schema. TEMPORARY, because a plain CREATE TABLE commits implicitly on MySQL and
 * would end the transaction DAMA wraps every test in; and dropped explicitly, because MySQL keeps a temporary table
 * across that transaction's rollback and the connection outlives the test.
 */
final class TemporaryMessengerMessagesTable
{
    private const string NAME = 'messenger_messages';

    private function __construct()
    {
    }

    public static function create(Connection $connection): void
    {
        $table = new Table(self::NAME);
        $table->addColumn('id', Types::INTEGER)->setAutoincrement(true);
        $table->addColumn('body', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);
        $table->addColumn('queue_name', Types::STRING, ['length' => 190]);
        $table->addColumn('created_at', Types::DATETIME_MUTABLE);
        $table->addColumn('available_at', Types::DATETIME_MUTABLE);
        $table->addColumn('delivered_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        foreach ($connection->getDatabasePlatform()->getCreateTableSQL($table) as $statement) {
            $connection->executeStatement(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $statement));
        }
    }

    public static function drop(Connection $connection): void
    {
        $statement = $connection->getDatabasePlatform() instanceof SQLitePlatform
            ? 'DROP TABLE IF EXISTS temp.' . self::NAME
            : 'DROP TEMPORARY TABLE IF EXISTS ' . self::NAME;

        $connection->executeStatement($statement);
    }
}
