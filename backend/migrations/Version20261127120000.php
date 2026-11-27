<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Labels for favorites and kept entries (#2581); every account starts with one, named in its language. */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Label and entry_label tables, with a read later label for every account (#2581).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();

        foreach ($this->isMySql() ? $this->mySqlTables() : $this->sqliteTables() as $statement) {
            $this->addSql($statement);
        }
        $this->addSql(
            'INSERT INTO label (user_id, name) SELECT id, CASE locale WHEN \'de\' THEN \'Später lesen\' '
            . 'WHEN \'fr\' THEN \'À lire plus tard\' WHEN \'es\' THEN \'Leer más tarde\' ELSE \'Read later\' END '
            . 'FROM app_user',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIfUnsupportedPlatform();

        $this->addSql('DROP TABLE entry_label');
        $this->addSql('DROP TABLE label');
    }

    public function isTransactional(): bool
    {
        return !$this->isMySql();
    }

    /**
     * @return list<string>
     */
    private function mySqlTables(): array
    {
        return [
            'CREATE TABLE label (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL COLLATE `utf8mb4_0900_bin`, '
            . 'user_id INT NOT NULL, INDEX IDX_EA750E8A76ED395 (user_id), '
            . 'UNIQUE INDEX uniq_label_user_name (user_id, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4',
            'CREATE TABLE entry_label (label_id INT NOT NULL, entry_id INT NOT NULL, '
            . 'INDEX IDX_8ABD334E33B92F39 (label_id), INDEX idx_entry_label_entry (entry_id), '
            . 'PRIMARY KEY (label_id, entry_id)) DEFAULT CHARACTER SET utf8mb4',
            'ALTER TABLE label ADD CONSTRAINT FK_EA750E8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) '
            . 'ON DELETE CASCADE',
            'ALTER TABLE entry_label ADD CONSTRAINT FK_8ABD334E33B92F39 FOREIGN KEY (label_id) REFERENCES label (id) '
            . 'ON DELETE CASCADE',
            'ALTER TABLE entry_label ADD CONSTRAINT FK_8ABD334EBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) '
            . 'ON DELETE CASCADE',
        ];
    }

    /**
     * @return list<string>
     */
    private function sqliteTables(): array
    {
        return [
            'CREATE TABLE label (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(60) NOT NULL, '
            . 'user_id INTEGER NOT NULL, CONSTRAINT FK_EA750E8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_EA750E8A76ED395 ON label (user_id)',
            'CREATE UNIQUE INDEX uniq_label_user_name ON label (user_id, name)',
            'CREATE TABLE entry_label (label_id INTEGER NOT NULL, entry_id INTEGER NOT NULL, '
            . 'PRIMARY KEY (label_id, entry_id), '
            . 'CONSTRAINT FK_8ABD334E33B92F39 FOREIGN KEY (label_id) REFERENCES label (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_8ABD334EBA364942 FOREIGN KEY (entry_id) REFERENCES entry (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_8ABD334E33B92F39 ON entry_label (label_id)',
            'CREATE INDEX idx_entry_label_entry ON entry_label (entry_id)',
        ];
    }

    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function abortIfUnsupportedPlatform(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );
    }
}
