<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261102130000;
use DoctrineMigrations\Version20261113100000;
use DoctrineMigrations\Version20261113130000;
use Psr\Log\NullLogger;

/**
 * The test schema comes from the mapping, so the For You table is rebuilt as it stood before the move. SQLite only:
 * MySQL DDL commits implicitly and would end the per-test transaction the suite rolls back.
 */
final class NetworkExclusionMigrationTest extends DbTestCase
{
    use SeedsUsers;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessSqlite();

        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once dirname(__DIR__, 2) . '/migrations/Version20261102130000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261113100000.php';
        require_once dirname(__DIR__, 2) . '/migrations/Version20261113130000.php';
        $this->userId = $this->user('network-exclusion-migration@example.test')->requireId();
        $this->setBackToBeforeTheMove();
    }

    public function testAForYouExclusionMovesToTheSharedTableAsAForYouRow(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO for_you_network_exclusion (user_id, network) VALUES (?, 'bluesky')",
            [$this->userId],
        );

        $this->runUp(new Version20261113100000($this->connection(), new NullLogger()));

        self::assertSame(
            [['network' => 'bluesky', 'surface' => 'for_you']],
            $this->connection()->fetchAllAssociative(
                'SELECT network, surface FROM network_exclusion WHERE user_id = ?',
                [$this->userId],
            ),
        );
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist(['for_you_network_exclusion']));
    }

    public function testDownKeepsOnlyTheForYouRows(): void
    {
        $move = new Version20261113100000($this->connection(), new NullLogger());
        $this->runUp($move);
        $this->connection()->executeStatement(
            "INSERT INTO network_exclusion (user_id, network, surface) VALUES (?, 'bluesky', 'for_you'),"
            . " (?, 'mastodon', 'all_items')",
            [$this->userId, $this->userId],
        );

        $this->runDown(new Version20261113100000($this->connection(), new NullLogger()));

        self::assertSame(
            ['bluesky'],
            $this->connection()->fetchFirstColumn(
                'SELECT network FROM for_you_network_exclusion WHERE user_id = ?',
                [$this->userId],
            ),
        );
        self::assertFalse($this->connection()->createSchemaManager()->tablesExist(['network_exclusion']));
        $this->runUp(new Version20261113100000($this->connection(), new NullLogger()));
    }

    public function testTheSurfacesTakeTheirUrlSlugsAndGiveThemBack(): void
    {
        $this->runUp(new Version20261113100000($this->connection(), new NullLogger()));
        $this->connection()->executeStatement(
            "INSERT INTO network_exclusion (user_id, network, surface) VALUES (?, 'bluesky', 'for_you'),"
            . " (?, 'mastodon', 'all_items'), (?, 'bluesky', 'trending')",
            [$this->userId, $this->userId, $this->userId],
        );

        $this->runUp(new Version20261113130000($this->connection(), new NullLogger()));
        self::assertSame(['for-you', 'all-items', 'trending'], $this->storedSurfaces());

        $this->runDown(new Version20261113130000($this->connection(), new NullLogger()));
        self::assertSame(['for_you', 'all_items', 'trending'], $this->storedSurfaces());
    }

    /** @return list<mixed> */
    private function storedSurfaces(): array
    {
        return $this->connection()->fetchFirstColumn(
            'SELECT surface FROM network_exclusion WHERE user_id = ? ORDER BY id',
            [$this->userId],
        );
    }

    private function setBackToBeforeTheMove(): void
    {
        $this->connection()->executeStatement('DROP TABLE network_exclusion');
        $this->runUp(new Version20261102130000($this->connection(), new NullLogger()));
    }

    private function runUp(AbstractMigration $migration): void
    {
        $migration->up($this->connection()->createSchemaManager()->introspectSchema());
        $this->execute($migration);
    }

    private function runDown(AbstractMigration $migration): void
    {
        $migration->down($this->connection()->createSchemaManager()->introspectSchema());
        $this->execute($migration);
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement());
        }
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
