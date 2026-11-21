<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Ingest\Model\CrossPostModel;
use App\Service\Ingestion\Ingest\Support\CrossPostHash;
use App\Service\Ingestion\Ingest\Support\LemmyLinkedUrl;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121120000 extends AbstractMigration
{
    private const int BATCH = 1000;
    private const string COLUMN = 'cross_post_hash';
    private const string INDEX = 'idx_canonical_item_cross_post_hash';

    public function getDescription(): string
    {
        return 'Key each Lemmy canonical item with the hash its cross-posts share (#2420).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('canonical_item')->hasColumn(self::COLUMN), self::COLUMN . ' already exists.');
        $this->requireSupportedPlatform();

        $this->addSql('ALTER TABLE canonical_item ADD ' . self::COLUMN . ' VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX ' . self::INDEX . ' ON canonical_item (' . self::COLUMN . ')');
        $this->queueBackfill();
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ANALYZE TABLE canonical_item');
        }
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('canonical_item')->hasColumn(self::COLUMN), self::COLUMN . ' does not exist.');
        $this->requireSupportedPlatform();

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('DROP INDEX ' . self::INDEX . ' ON canonical_item');
        } else {
            $this->addSql('DROP INDEX ' . self::INDEX);
        }
        $this->addSql('ALTER TABLE canonical_item DROP COLUMN ' . self::COLUMN);
    }

    /** MySQL commits each DDL statement, so a transaction would protect nothing there. */
    public function isTransactional(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    private function queueBackfill(): void
    {
        $urlNormalizer = new UrlNormalizer();
        $lastId = 0;
        do {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT ci.id, e.author_url, e.title, e.content_html FROM canonical_item ci'
                . ' JOIN entry e ON e.id = (SELECT MIN(first_copy.id) FROM entry first_copy'
                . ' WHERE first_copy.canonical_item_id = ci.id)'
                . " WHERE ci.kind = 'lemmy' AND ci.id > :lastId ORDER BY ci.id LIMIT " . self::BATCH,
                ['lastId' => $lastId],
            );
            $hashes = [];
            foreach ($rows as $row) {
                $hash = CrossPostHash::build(new CrossPostModel(
                    $row['author_url'],
                    $urlNormalizer->normalize(LemmyLinkedUrl::parse($row['content_html'])),
                    (string) $row['title'],
                    $row['content_html'],
                ));
                if ($hash !== null) {
                    $hashes[(int) $row['id']] = $hash;
                }
                $lastId = (int) $row['id'];
            }
            $this->queueHashes($hashes);
        } while (\count($rows) === self::BATCH);
    }

    /** @param array<int, string> $hashes */
    private function queueHashes(array $hashes): void
    {
        if ($hashes === []) {
            return;
        }
        $ids = array_keys($hashes);
        $cases = [];
        $parameters = [];
        $types = [];
        foreach ($hashes as $id => $hash) {
            $cases[] = 'WHEN ? THEN ?';
            array_push($parameters, $id, $hash);
            array_push($types, ParameterType::INTEGER, ParameterType::STRING);
        }
        $this->addSql(
            'UPDATE canonical_item SET ' . self::COLUMN . ' = CASE id ' . implode(' ', $cases) . ' END'
            . ' WHERE id IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')',
            [...$parameters, ...$ids],
            [...$types, ...array_fill(0, \count($ids), ParameterType::INTEGER)],
        );
    }

    private function requireSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
