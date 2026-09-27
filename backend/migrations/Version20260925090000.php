<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Rows written before #493's removal of PHASE_DEDUP (9eeff8da) have no case
 * in App\Enum\CallPhase (#1162): hydrating one now throws \ValueError.
 */
final class Version20260925090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Delete retired recommendation_run_log rows with phase = 'dedup' (#1162).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM recommendation_run_log WHERE phase = 'dedup'");
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Deleted debug-log rows carry no original values to restore.');
    }
}
