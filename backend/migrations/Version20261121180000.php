<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121180000 extends AbstractMigration
{
    private const array COLUMNS = ['subject_post_key_hash', 'subject_answered_key_hash', 'subject_root_key_hash'];

    public function getDescription(): string
    {
        return 'Store the key hashes of the post a social notification is about (#2391).';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('social_notification');
        foreach (self::COLUMNS as $column) {
            if ($table->hasColumn($column)) {
                continue;
            }
            $this->addSql('ALTER TABLE social_notification ADD ' . $column . ' VARCHAR(64) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('social_notification');
        foreach (self::COLUMNS as $column) {
            if (!$table->hasColumn($column)) {
                continue;
            }
            $this->addSql('ALTER TABLE social_notification DROP COLUMN ' . $column);
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
