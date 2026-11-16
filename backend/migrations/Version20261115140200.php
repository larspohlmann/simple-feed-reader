<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Drops the card copies Version20261115140100 merged into shared_link (#2239), after linking the cards and quotes the
 * old release wrote while the merge ran: a deploy migrates before it flips, so the old code still ingests. SQLite
 * rebuilds link_card, which has no trigger, and drops entry.quoted_link_card in place, so entry keeps its FTS5 triggers.
 */
final class Version20261115140200 extends AbstractMigration
{
    private const string CARD_COLUMNS = 'url, title, description, image_url, site_name';
    private const string SHARED_LINK_KEY = 'FK_A7530A7A8C730EC6';

    public function getDescription(): string
    {
        return 'Drop the link card columns and entry.quoted_link_card now kept on shared_link (#2239)';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql($schema);

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->linkStragglers();
            $this->upSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the shared_link slim migration.');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->downMySql();

            return;
        }
        if ($platform instanceof SQLitePlatform) {
            $this->downSqlite();

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the shared_link slim migration.');
    }

    private function linkStragglers(): void
    {
        require_once __DIR__ . '/Version20261115140100.php';
        $merge = new Version20261115140100($this->connection, $this->forwardingLogger());
        $merge->linkUnlinked();
        foreach ($merge->getSql() as $query) {
            $this->addSql($query->getStatement());
        }
    }

    /** The merge's notices and warnings, written as this migration's own. */
    private function forwardingLogger(): LoggerInterface
    {
        return new class ($this->write(...), $this->warnIf(...)) extends AbstractLogger {
            public function __construct(private readonly \Closure $write, private readonly \Closure $warn)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $level === LogLevel::WARNING ? ($this->warn)(true, (string) $message) : ($this->write)((string) $message);
            }
        };
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /**
     * MySQL 8.0's copy algorithm rejects a nullability change on a foreign key column (1832, #2254), so the key is
     * dropped in the same atomic ALTER and restored after it; a retry resumes from whichever statement did not run.
     */
    private function upMySql(Schema $schema): void
    {
        $cards = $schema->getTable('link_card');
        $slimmed = !$cards->hasColumn('url');
        if (!$slimmed) {
            $this->linkStragglers();
            $this->addSql(
                'ALTER TABLE link_card DROP FOREIGN KEY ' . self::SHARED_LINK_KEY . ', DROP url, DROP title,'
                . ' DROP description, DROP image_url, DROP site_name, MODIFY shared_link_id INT NOT NULL',
            );
        }
        if (!$slimmed || !$cards->hasForeignKey(self::SHARED_LINK_KEY)) {
            $this->addSql(
                'ALTER TABLE link_card ADD CONSTRAINT ' . self::SHARED_LINK_KEY
                . ' FOREIGN KEY (shared_link_id) REFERENCES shared_link (id)',
            );
        }
        if ($schema->getTable('entry')->hasColumn('quoted_link_card')) {
            $this->addSql('ALTER TABLE entry DROP quoted_link_card');
        }
    }

    private function upSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE link_card_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                position SMALLINT NOT NULL,
                entry_id INTEGER NOT NULL,
                thread_post_id INTEGER DEFAULT NULL,
                shared_link_id INTEGER NOT NULL,
                CONSTRAINT FK_A7530A7AFA33EB65 FOREIGN KEY (thread_post_id) REFERENCES thread_post (id) ON DELETE CASCADE,
                CONSTRAINT FK_A7530A7ABA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE,
                CONSTRAINT FK_A7530A7A8C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id)
            )
            SQL);
        $this->addSql(
            'INSERT INTO link_card_new (id, position, entry_id, thread_post_id, shared_link_id)'
            . ' SELECT id, position, entry_id, thread_post_id, shared_link_id FROM link_card',
        );
        $this->addSql('DROP TABLE link_card');
        $this->addSql('ALTER TABLE link_card_new RENAME TO link_card');
        $this->createLinkCardIndexes();
        $this->addSql('ALTER TABLE entry DROP COLUMN quoted_link_card');
    }

    private function downMySql(): void
    {
        $this->addSql(
            'ALTER TABLE link_card ADD url VARCHAR(2048) DEFAULT NULL, ADD title VARCHAR(500) DEFAULT NULL,'
            . ' ADD description LONGTEXT DEFAULT NULL, ADD image_url VARCHAR(2048) DEFAULT NULL,'
            . ' ADD site_name VARCHAR(255) DEFAULT NULL, MODIFY shared_link_id INT DEFAULT NULL',
        );
        $this->addSql(
            'UPDATE link_card card JOIN shared_link link ON link.id = card.shared_link_id SET card.url = link.url,'
            . ' card.title = link.title, card.description = link.description, card.image_url = link.image_url,'
            . ' card.site_name = link.site_name',
        );
        $this->addSql(
            'ALTER TABLE link_card MODIFY url VARCHAR(2048) NOT NULL, MODIFY title VARCHAR(500) NOT NULL,'
            . ' MODIFY site_name VARCHAR(255) NOT NULL',
        );
        $this->addSql('ALTER TABLE entry ADD quoted_link_card JSON DEFAULT NULL');
        $this->addSql(
            'UPDATE entry JOIN shared_link link ON link.id = entry.quoted_shared_link_id SET entry.quoted_link_card ='
            . " JSON_OBJECT('url', link.url, 'title', link.title, 'description', link.description,"
            . " 'imageUrl', link.image_url, 'siteName', link.site_name)",
        );
    }

    private function downSqlite(): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE link_card_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                position SMALLINT NOT NULL,
                entry_id INTEGER NOT NULL,
                thread_post_id INTEGER DEFAULT NULL,
                shared_link_id INTEGER DEFAULT NULL,
                url VARCHAR(2048) NOT NULL,
                title VARCHAR(500) NOT NULL,
                description CLOB DEFAULT NULL,
                image_url VARCHAR(2048) DEFAULT NULL,
                site_name VARCHAR(255) NOT NULL,
                CONSTRAINT FK_A7530A7AFA33EB65 FOREIGN KEY (thread_post_id) REFERENCES thread_post (id) ON DELETE CASCADE,
                CONSTRAINT FK_A7530A7ABA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE,
                CONSTRAINT FK_A7530A7A8C730EC6 FOREIGN KEY (shared_link_id) REFERENCES shared_link (id)
            )
            SQL);
        $this->addSql(sprintf(
            'INSERT INTO link_card_old (id, position, entry_id, thread_post_id, shared_link_id, %1$s)'
            . ' SELECT card.id, card.position, card.entry_id, card.thread_post_id, card.shared_link_id,'
            . ' link.url, link.title, link.description, link.image_url, link.site_name'
            . ' FROM link_card card JOIN shared_link link ON link.id = card.shared_link_id',
            self::CARD_COLUMNS,
        ));
        $this->addSql('DROP TABLE link_card');
        $this->addSql('ALTER TABLE link_card_old RENAME TO link_card');
        $this->createLinkCardIndexes();
        $this->addSql('ALTER TABLE entry ADD COLUMN quoted_link_card CLOB DEFAULT NULL');
        $this->addSql(
            "UPDATE entry SET quoted_link_card = (SELECT json_object('url', link.url, 'title', link.title,"
            . " 'description', link.description, 'imageUrl', link.image_url, 'siteName', link.site_name)"
            . ' FROM shared_link link WHERE link.id = entry.quoted_shared_link_id)'
            . ' WHERE quoted_shared_link_id IS NOT NULL',
        );
    }

    private function createLinkCardIndexes(): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_link_card_entry_position ON link_card (entry_id, position)');
        $this->addSql('CREATE INDEX IDX_A7530A7ABA364942 ON link_card (entry_id)');
        $this->addSql('CREATE INDEX IDX_A7530A7AFA33EB65 ON link_card (thread_post_id)');
        $this->addSql('CREATE INDEX IDX_A7530A7A8C730EC6 ON link_card (shared_link_id)');
    }
}
