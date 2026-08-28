<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email digest: per-user cadence, per-search flag, verified-email marker (#636).';
    }

    public function up(Schema $schema): void
    {
        $this->assertSupportedPlatform();

        $this->addSql("ALTER TABLE user_preferences ADD digest_enabled TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_cadence VARCHAR(10) DEFAULT 'daily' NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_send_hour SMALLINT DEFAULT 8 NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_weekday SMALLINT DEFAULT 1 NOT NULL");
        $this->addSql("ALTER TABLE user_preferences ADD digest_last_sent_at DATETIME DEFAULT NULL");

        $this->addSql("ALTER TABLE saved_search ADD include_in_digest TINYINT(1) DEFAULT 0 NOT NULL");

        $this->addSql("ALTER TABLE app_user ADD email_verified_at DATETIME DEFAULT NULL");
        // Backfill: any account already Active or awaiting approval reached that
        // state by proving its address (email verify token or provider), on an
        // instance that had mail enabled at registration. Seed the marker from
        // the best timestamp we hold.
        $this->addSql(
            "UPDATE app_user SET email_verified_at = COALESCE(approved_at, created_at) "
            . "WHERE status IN ('active', 'pending_approval')",
        );
    }

    public function down(Schema $schema): void
    {
        $this->assertSupportedPlatform();
        $this->addSql('ALTER TABLE user_preferences DROP digest_enabled');
        $this->addSql('ALTER TABLE user_preferences DROP digest_cadence');
        $this->addSql('ALTER TABLE user_preferences DROP digest_send_hour');
        $this->addSql('ALTER TABLE user_preferences DROP digest_weekday');
        $this->addSql('ALTER TABLE user_preferences DROP digest_last_sent_at');
        $this->addSql('ALTER TABLE saved_search DROP include_in_digest');
        $this->addSql('ALTER TABLE app_user DROP email_verified_at');
    }

    private function assertSupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !($platform instanceof AbstractMySQLPlatform) && !($platform instanceof SQLitePlatform),
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
