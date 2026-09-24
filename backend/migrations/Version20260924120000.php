<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924120000 extends AbstractMigration
{
    private const array COLUMNS = [
        'discussion_url' => 'VARCHAR(2048) DEFAULT NULL',
        'comments_feed_url' => 'VARCHAR(2048) DEFAULT NULL',
        'comments_load' => 'VARCHAR(8) DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add the entry discussion columns (#1140).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('entry')->hasColumn('discussion_url'), 'entry.discussion_url already exists.');

        foreach (self::COLUMNS as $name => $definition) {
            $this->addSql(sprintf('ALTER TABLE entry ADD COLUMN %s %s', $name, $definition));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::COLUMNS) as $name) {
            $this->addSql(sprintf('ALTER TABLE entry DROP COLUMN %s', $name));
        }
    }
}
