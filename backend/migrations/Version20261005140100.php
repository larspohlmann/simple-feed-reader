<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Until #1395 a model id starting with `jev-`, case-sensitively, made a connection TypeSafe's System One. */
final class Version20261005140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Backfill the engine kind and scoring protocol; a run's kind 'jev' becomes 'scoring' (#1395).";
    }

    public function up(Schema $schema): void
    {
        $jevModel = $this->mysql() ? "CAST(LEFT(model, 4) AS BINARY) = 'jev-'" : "substr(model, 1, 4) = 'jev-'";
        $this->addSql(
            "UPDATE user_ai_settings SET model_kind = 'scoring', scoring_protocol = 'system_one' WHERE " . $jevModel,
        );
        $this->addSql("UPDATE user_ai_settings SET model_kind = 'llm' WHERE model IS NOT NULL AND model_kind IS NULL");
        $this->addSql(
            "UPDATE recommendation_run SET engine_kind = 'scoring', scoring_protocol = 'system_one' "
            . "WHERE engine_kind = 'jev'",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE recommendation_run SET engine_kind = 'jev', scoring_protocol = NULL WHERE engine_kind = 'scoring'",
        );
        $this->addSql('UPDATE user_ai_settings SET model_kind = NULL, scoring_protocol = NULL');
    }

    /** Refuses any platform but the two supported ones: better a refusal than SQL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No SQL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
