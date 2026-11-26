<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Only the column default moves: every stored row keeps the style its account has (#2565).
 * SQLite is rebuilt by hand: Doctrine's own rebuild turns user_id into an AUTOINCREMENT key.
 */
final class Version20261126120000 extends AbstractMigration
{
    private const string TABLE = 'user_reading_settings';
    private const string REBUILT = 'user_reading_settings_rebuilt';
    private const string COLUMNS = 'scrape_fallback_enabled, magazine_style, trending_enabled, user_id, handedness, '
        . 'sidebar_style, theme';

    public function getDescription(): string
    {
        return 'New accounts start with the airy sidebar (#2565).';
    }

    public function up(Schema $schema): void
    {
        $this->addDefaultChange('airy');
    }

    public function down(Schema $schema): void
    {
        $this->addDefaultChange('boxed');
    }

    public function isTransactional(): bool
    {
        return !$this->isMySql();
    }

    private function addDefaultChange(string $sidebarStyle): void
    {
        $this->abortIfUnsupportedPlatform();

        if ($this->isMySql()) {
            $this->addSql(\sprintf(
                'ALTER TABLE %s ALTER COLUMN sidebar_style SET DEFAULT \'%s\'',
                self::TABLE,
                $sidebarStyle,
            ));

            return;
        }

        $this->addSql($this->buildSqliteTable($sidebarStyle));
        $this->addSql(\sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s',
            self::REBUILT,
            self::COLUMNS,
            self::COLUMNS,
            self::TABLE,
        ));
        $this->addSql(\sprintf('DROP TABLE %s', self::TABLE));
        $this->addSql(\sprintf('ALTER TABLE %s RENAME TO %s', self::REBUILT, self::TABLE));
    }

    private function buildSqliteTable(string $sidebarStyle): string
    {
        return \sprintf(
            'CREATE TABLE %s (scrape_fallback_enabled BOOLEAN DEFAULT 0 NOT NULL, '
            . 'magazine_style VARCHAR(10) DEFAULT \'boxed\' NOT NULL, trending_enabled BOOLEAN DEFAULT 1 NOT NULL, '
            . 'user_id INT NOT NULL, handedness VARCHAR(5) DEFAULT \'right\' NOT NULL, '
            . 'sidebar_style VARCHAR(10) DEFAULT \'%s\' NOT NULL, theme VARCHAR(10) DEFAULT \'graphite\' NOT NULL, '
            . 'PRIMARY KEY (user_id), CONSTRAINT FK_CEA7BFA8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            self::REBUILT,
            $sidebarStyle,
        );
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
