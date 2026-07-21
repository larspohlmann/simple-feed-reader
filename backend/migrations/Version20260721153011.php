<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The initial schema: every table plans 1-3 introduced, in one migration.
 *
 * Generated with doctrine:migrations:diff and then rewritten by hand, for one
 * reason that matters: `diff` emits DDL for whichever platform the connection
 * it ran against happens to speak. Run in dev it produces SQLite — INTEGER
 * PRIMARY KEY AUTOINCREMENT, CLOB, NOT DEFERRABLE INITIALLY IMMEDIATE — none of
 * which MySQL will parse. Shipping that verbatim means the first production
 * deploy dies on the first CREATE TABLE, and nothing in the test suite would
 * have said so, because tests build their schema from ORM metadata via
 * doctrine:schema:create and never execute a migration at all.
 *
 * So both dialects are carried explicitly. The MySQL branch is the one that
 * runs in production; the SQLite branch exists so that the chain is executable
 * — and therefore testable — on the engine dev and CI actually use. Both were
 * generated from the same metadata (schema:create --dump-sql against each
 * platform), so they are transcriptions rather than translations.
 *
 * ORDERING. This sorts AFTER Version20260721150000, which is data-only and
 * lowercases app_user.email. That looks backwards — a data fix for a column
 * that does not exist yet — but it is correct in both directions:
 *
 *   - From empty: 150000 finds no app_user, hits its own skipIf guard and does
 *     nothing (there are no rows to normalise on a database with no rows), then
 *     this migration builds the schema. Verified by execution against a
 *     scratch SQLite file and a scratch MySQL 8.4 database.
 *   - On a database whose schema predates migrations: 150000 does its real
 *     work, and this migration then refuses rather than colliding — see below.
 *
 * ADDITIVE ONLY. up() contains no DROP, no ALTER that narrows a column, and no
 * constraint added to an existing table. The deploy migrates before flipping
 * the `current` symlink, so the previous release keeps running against the
 * migrated schema; that only holds while migrations stay additive.
 *
 * CASCADES ARE LOAD-BEARING, not decoration. App\Entity\User has no ORM
 * association mappings at all, so $em->remove($user) emits exactly one
 * statement: DELETE FROM app_user. Every dependent row — action_token above
 * all, which App\Command\PurgeUnverifiedUsersCommand relies on — is removed by
 * the database FK cascade and by nothing else. Drop ON DELETE CASCADE from
 * action_token.user_id and the purge command silently orphans token rows on
 * MySQL while the suite stays green, because SQLite in the test bootstrap gets
 * its constraints from the same metadata rather than from this file.
 */
final class Version20260721153011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, identities, action tokens, feeds, entries, subscriptions, tags, entry state, lock keys.';
    }

    public function up(Schema $schema): void
    {
        // A database that already has app_user was built by
        // doctrine:schema:create rather than by this chain, so running these
        // CREATE TABLEs would fail on the first one with an opaque driver
        // error, halfway through. Say what happened and what to do instead:
        // baseline with `doctrine:migrations:version --add --all`, which marks
        // the chain executed without running it.
        $this->abortIf(
            $schema->hasTable('app_user'),
            'app_user already exists, so this schema was not built by the migration chain. '
            . 'Baseline it with `bin/console doctrine:migrations:version --add --all` instead of running this migration.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upMySql();

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->upSqlite();

            return;
        }

        // Better a refusal than DDL invented for a platform nobody tested.
        $this->abortIf(true, \sprintf(
            'No schema defined for platform %s; only MySQL and SQLite are supported.',
            $platform::class,
        ));
    }

    public function down(Schema $schema): void
    {
        // Reverse dependency order so the drops work on MySQL, where the FKs
        // are real objects and are not dropped implicitly with their table.
        foreach ([
            'subscription_tag',
            'entry_state',
            'action_token',
            'user_identity',
            'subscription',
            'tag',
            'entry',
            'feed',
            'app_user',
            'lock_keys',
        ] as $table) {
            $this->addSql('DROP TABLE ' . $table);
        }
    }

    /**
     * Production. Tables first, then foreign keys, so creation order does not
     * have to be a topological sort of the reference graph.
     */
    private function upMySql(): void
    {
        $this->addSql('CREATE TABLE app_user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, roles JSON NOT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, approved_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_identity (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(30) NOT NULL, provider_user_id VARCHAR(191) NOT NULL, email VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_8A180DC4A76ED395 (user_id), UNIQUE INDEX uniq_identity_provider_uid (provider, provider_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE action_token (id INT AUTO_INCREMENT NOT NULL, purpose VARCHAR(30) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_B8F96D87A76ED395 (user_id), UNIQUE INDEX uniq_action_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE feed (id INT AUTO_INCREMENT NOT NULL, url VARCHAR(750) NOT NULL, site_url VARCHAR(2048) DEFAULT NULL, title VARCHAR(512) DEFAULT NULL, description LONGTEXT DEFAULT NULL, favicon_url VARCHAR(2048) DEFAULT NULL, status VARCHAR(20) NOT NULL, last_fetched_at DATETIME DEFAULT NULL, next_fetch_at DATETIME DEFAULT NULL, fetch_interval_minutes INT NOT NULL, consecutive_failures INT NOT NULL, last_error_message LONGTEXT DEFAULT NULL, etag VARCHAR(512) DEFAULT NULL, last_modified VARCHAR(255) DEFAULT NULL, UNIQUE INDEX uniq_feed_url (url), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entry (id INT AUTO_INCREMENT NOT NULL, guid LONGTEXT NOT NULL, guid_hash VARCHAR(64) NOT NULL, url VARCHAR(2048) DEFAULT NULL, title VARCHAR(1024) NOT NULL, author VARCHAR(255) DEFAULT NULL, summary LONGTEXT DEFAULT NULL, content_html LONGTEXT DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, feed_id INT NOT NULL, INDEX IDX_2B219D7051A5BC03 (feed_id), INDEX idx_entry_feed_published (feed_id, published_at), UNIQUE INDEX uniq_entry_feed_guid (feed_id, guid_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(20) DEFAULT NULL, icon VARCHAR(64) DEFAULT NULL, user_id INT NOT NULL, INDEX IDX_389B783A76ED395 (user_id), UNIQUE INDEX uniq_tag_user_name (user_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, custom_title VARCHAR(512) DEFAULT NULL, marked_read_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, feed_id INT NOT NULL, INDEX IDX_A3C664D3A76ED395 (user_id), INDEX IDX_A3C664D351A5BC03 (feed_id), UNIQUE INDEX uniq_subscription_user_feed (user_id, feed_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE subscription_tag (subscription_id INT NOT NULL, tag_id INT NOT NULL, INDEX IDX_E7A259689A1887DC (subscription_id), INDEX IDX_E7A25968BAD26311 (tag_id), PRIMARY KEY (subscription_id, tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE entry_state (is_read TINYINT NOT NULL, is_favorite TINYINT NOT NULL, is_kept TINYINT NOT NULL, read_at DATETIME DEFAULT NULL, user_id INT NOT NULL, entry_id INT NOT NULL, INDEX IDX_2789B15DA76ED395 (user_id), INDEX IDX_2789B15DBA364942 (entry_id), PRIMARY KEY (user_id, entry_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT UNSIGNED NOT NULL, PRIMARY KEY (key_id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE user_identity ADD CONSTRAINT FK_8A180DC4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        // Load-bearing: PurgeUnverifiedUsersCommand deletes users and nothing else.
        $this->addSql('ALTER TABLE action_token ADD CONSTRAINT FK_B8F96D87A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry ADD CONSTRAINT FK_2B219D7051A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B783A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D351A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription_tag ADD CONSTRAINT FK_E7A259689A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscription_tag ADD CONSTRAINT FK_E7A25968BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_state ADD CONSTRAINT FK_2789B15DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE entry_state ADD CONSTRAINT FK_2789B15DBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');
    }

    /**
     * Dev, test and the CI sqlite leg. SQLite cannot add a foreign key to an
     * existing table, so each one is inlined in its CREATE TABLE and the tables
     * therefore have to be created parents-first.
     */
    private function upSqlite(): void
    {
        $this->addSql('CREATE TABLE app_user (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) DEFAULT NULL, roles CLOB NOT NULL, status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, approved_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON app_user (email)');

        $this->addSql('CREATE TABLE user_identity (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, provider VARCHAR(30) NOT NULL, provider_user_id VARCHAR(191) NOT NULL, email VARCHAR(180) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_8A180DC4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_8A180DC4A76ED395 ON user_identity (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_provider_uid ON user_identity (provider, provider_user_id)');

        // Load-bearing: PurgeUnverifiedUsersCommand deletes users and nothing else.
        $this->addSql('CREATE TABLE action_token (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, purpose VARCHAR(30) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, consumed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_B8F96D87A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_B8F96D87A76ED395 ON action_token (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_action_token_hash ON action_token (token_hash)');

        $this->addSql('CREATE TABLE feed (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, url VARCHAR(750) NOT NULL, site_url VARCHAR(2048) DEFAULT NULL, title VARCHAR(512) DEFAULT NULL, description CLOB DEFAULT NULL, favicon_url VARCHAR(2048) DEFAULT NULL, status VARCHAR(20) NOT NULL, last_fetched_at DATETIME DEFAULT NULL, next_fetch_at DATETIME DEFAULT NULL, fetch_interval_minutes INTEGER NOT NULL, consecutive_failures INTEGER NOT NULL, last_error_message CLOB DEFAULT NULL, etag VARCHAR(512) DEFAULT NULL, last_modified VARCHAR(255) DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_feed_url ON feed (url)');

        $this->addSql('CREATE TABLE entry (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, guid CLOB NOT NULL, guid_hash VARCHAR(64) NOT NULL, url VARCHAR(2048) DEFAULT NULL, title VARCHAR(1024) NOT NULL, author VARCHAR(255) DEFAULT NULL, summary CLOB DEFAULT NULL, content_html CLOB DEFAULT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, feed_id INTEGER NOT NULL, CONSTRAINT FK_2B219D7051A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2B219D7051A5BC03 ON entry (feed_id)');
        $this->addSql('CREATE INDEX idx_entry_feed_published ON entry (feed_id, published_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_entry_feed_guid ON entry (feed_id, guid_hash)');

        $this->addSql('CREATE TABLE tag (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(20) DEFAULT NULL, icon VARCHAR(64) DEFAULT NULL, user_id INTEGER NOT NULL, CONSTRAINT FK_389B783A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_389B783A76ED395 ON tag (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tag_user_name ON tag (user_id, name)');

        $this->addSql('CREATE TABLE subscription (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, custom_title VARCHAR(512) DEFAULT NULL, marked_read_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INTEGER NOT NULL, feed_id INTEGER NOT NULL, CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_A3C664D351A5BC03 FOREIGN KEY (feed_id) REFERENCES feed (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_A3C664D3A76ED395 ON subscription (user_id)');
        $this->addSql('CREATE INDEX IDX_A3C664D351A5BC03 ON subscription (feed_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_user_feed ON subscription (user_id, feed_id)');

        $this->addSql('CREATE TABLE subscription_tag (subscription_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (subscription_id, tag_id), CONSTRAINT FK_E7A259689A1887DC FOREIGN KEY (subscription_id) REFERENCES subscription (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E7A25968BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E7A259689A1887DC ON subscription_tag (subscription_id)');
        $this->addSql('CREATE INDEX IDX_E7A25968BAD26311 ON subscription_tag (tag_id)');

        $this->addSql('CREATE TABLE entry_state (is_read BOOLEAN NOT NULL, is_favorite BOOLEAN NOT NULL, is_kept BOOLEAN NOT NULL, read_at DATETIME DEFAULT NULL, user_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, PRIMARY KEY (user_id, entry_id), CONSTRAINT FK_2789B15DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2789B15DBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2789B15DA76ED395 ON entry_state (user_id)');
        $this->addSql('CREATE INDEX IDX_2789B15DBA364942 ON entry_state (entry_id)');

        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INTEGER UNSIGNED NOT NULL, PRIMARY KEY (key_id))');
    }
}
