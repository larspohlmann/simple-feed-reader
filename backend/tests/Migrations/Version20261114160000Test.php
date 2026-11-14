<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\Feed;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261114160000;
use Psr\Log\NullLogger;

/** The test schema comes from the mapping, so the rows are set back to how they stood before the migration. */
final class Version20261114160000Test extends DbTestCase
{
    private const string COMMUNITY = 'https://feddit.example/feeds/c/rss.xml';
    private const string USER = 'https://lemmy.example/feeds/u/ada.xml';
    private const string BLOG = 'https://blog.example/feed.xml';

    public function testCommunityFeedsMoveToLemmy(): void
    {
        $this->seedUnclassified();

        $this->migrate('up');

        self::assertSame('lemmy', $this->networkOf(self::COMMUNITY));
        self::assertNull($this->networkOf(self::USER));
        self::assertNull($this->networkOf(self::BLOG));
    }

    public function testDownClearsIt(): void
    {
        $this->seedUnclassified();
        $this->migrate('up');

        $this->migrate('down');

        self::assertNull($this->networkOf(self::COMMUNITY));
    }

    private function seedUnclassified(): void
    {
        foreach ([self::COMMUNITY, self::USER, self::BLOG] as $url) {
            $this->entityManager->persist(new Feed($url));
        }
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE feed SET network = NULL');
        $this->entityManager->clear();
    }

    private function migrate(string $direction): void
    {
        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once dirname(__DIR__, 2) . '/migrations/Version20261114160000.php';
        $connection = $this->entityManager->getConnection();
        $migration = new Version20261114160000($connection, new NullLogger());
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    private function networkOf(string $url): mixed
    {
        return $this->entityManager->getConnection()->fetchOne('SELECT network FROM feed WHERE url = ?', [$url]);
    }
}
