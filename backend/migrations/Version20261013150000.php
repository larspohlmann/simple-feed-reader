<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Social\Support\SocialFeedClassifier;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feed.network and backfill it with SocialFeedClassifier\'s verdict on every feed (#1606).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('feed')->hasColumn('network'), 'feed.network already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE feed ADD network VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_feed_network ON feed (network)');
    }

    public function postUp(Schema $schema): void
    {
        /** @var list<array{id: int|string, url: string, source_format: string}> $feeds */
        $feeds = $this->connection->fetchAllAssociative('SELECT id, url, source_format FROM feed');
        foreach ($feeds as $feed) {
            $network = SocialFeedClassifier::networkFor($feed['url'], $feed['source_format']);
            if (null === $network) {
                continue;
            }
            $this->connection->executeStatement(
                'UPDATE feed SET network = :network WHERE id = :id',
                ['network' => $network->value, 'id' => (int) $feed['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('feed')->hasColumn('network'), 'feed.network is already gone.');

        $this->abortIfUnsupportedPlatform();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX idx_feed_network ON feed');
        } else {
            $this->addSql('DROP INDEX idx_feed_network');
        }
        $this->addSql('ALTER TABLE feed DROP COLUMN network');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
