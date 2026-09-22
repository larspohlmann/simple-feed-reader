<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Give every saved search a stable URL slug ("<id>-<slug of term>"). Adds the
 * nullable column, backfills existing rows from their id and term, then adds
 * the per-user unique index. The backfill must match App\Service\Search\SavedSearchSlug.
 */
final class Version20260922173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add saved_search.slug and backfill it (#1118).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('saved_search')->hasColumn('slug'),
            'saved_search.slug already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE saved_search ADD slug VARCHAR(130) DEFAULT NULL');
        } elseif ($platform instanceof SQLitePlatform) {
            $this->addSql('ALTER TABLE saved_search ADD COLUMN slug VARCHAR(130) DEFAULT NULL');
        } else {
            throw new \RuntimeException('Unsupported database platform for the saved-search slug migration.');
        }
    }

    public function postUp(Schema $schema): void
    {
        $slugger = new AsciiSlugger();
        /** @var list<array{id: int, term: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, term FROM saved_search');
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $readable = $slugger->slug((string) $row['term'])->lower()->toString();
            $slug = $readable === '' ? (string) $id : $id . '-' . $readable;
            $this->connection->executeStatement(
                'UPDATE saved_search SET slug = :slug WHERE id = :id',
                ['slug' => $slug, 'id' => $id],
            );
        }

        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX uniq_saved_search_user_slug ON saved_search (user_id, slug)',
        );
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX uniq_saved_search_user_slug ON saved_search');
        } else {
            $this->addSql('DROP INDEX uniq_saved_search_user_slug');
        }
        $this->addSql('ALTER TABLE saved_search DROP COLUMN slug');
    }
}
