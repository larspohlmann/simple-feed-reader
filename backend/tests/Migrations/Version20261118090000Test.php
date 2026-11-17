<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20261118090000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class Version20261118090000Test extends TestCase
{
    private const string ICON = 'https://lemmy.world/pictrs/image/icon.png';

    private Connection $connection;

    protected function setUp(): void
    {
        require_once \dirname(__DIR__, 2) . '/migrations/Version20261118090000.php';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE feed (id INTEGER PRIMARY KEY, url VARCHAR(2048) NOT NULL, favicon_url VARCHAR(2048) DEFAULT NULL)',
        );
    }

    public function testUpClearsTheIconOfLemmyActorFeedsOnly(): void
    {
        $urls = [
            1 => 'https://lemmy.world/feeds/c/a.xml',
            2 => 'https://lemmy.world/feeds/c/technology.xml?sort=New',
            3 => 'https://lemmy.world/feeds/u/lars.xml',
            4 => 'https://lemmy.world/feeds/local.xml',
            5 => 'https://blog.example.com/feed.xml',
        ];
        foreach ($urls as $id => $url) {
            $this->connection->executeStatement(
                'INSERT INTO feed (id, url, favicon_url) VALUES (?, ?, ?)',
                [$id, $url, self::ICON],
            );
        }

        $this->migrateUp();

        $icons = $this->connection->fetchAllKeyValue('SELECT id, favicon_url FROM feed ORDER BY id');
        self::assertSame(
            [1 => null, 2 => null, 3 => null, 4 => self::ICON, 5 => self::ICON],
            $icons,
        );
    }

    private function migrateUp(): void
    {
        $migration = new Version20261118090000($this->connection, new NullLogger());
        $migration->up($this->connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
