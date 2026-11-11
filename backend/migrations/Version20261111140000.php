<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111140000 extends AbstractMigration
{
    private const string TABLE = 'user_feed_directory_scope';

    public function getDescription(): string
    {
        return 'Records the countries and languages the feed directory shows: user_feed_directory_scope (#2176).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable(self::TABLE), 'Already applied.');

        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('CREATE TABLE user_feed_directory_scope (countries JSON NOT NULL, languages JSON NOT NULL,'
                . ' user_id INT NOT NULL, PRIMARY KEY (user_id)) DEFAULT CHARACTER SET utf8mb4');
            $this->addSql('ALTER TABLE user_feed_directory_scope ADD CONSTRAINT FK_93B446FDA76ED395'
                . ' FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
            $this->addSql('INSERT INTO user_feed_directory_scope (user_id, countries, languages)'
                . ' SELECT id, JSON_ARRAY(), JSON_ARRAY() FROM app_user');

            return;
        }

        // INT, not INTEGER: a lone INTEGER primary key is a rowid alias, which Doctrine reads back as auto-increment.
        $this->addSql('CREATE TABLE user_feed_directory_scope (countries CLOB NOT NULL, languages CLOB NOT NULL,'
            . ' user_id INT NOT NULL, PRIMARY KEY (user_id),'
            . ' CONSTRAINT FK_93B446FDA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO user_feed_directory_scope (user_id, countries, languages)'
            . ' SELECT id, \'[]\', \'[]\' FROM app_user');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable(self::TABLE), 'Not applied.');

        $this->addSql('DROP TABLE user_feed_directory_scope');
    }
}
