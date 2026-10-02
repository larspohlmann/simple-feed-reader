<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002150000 extends AbstractMigration
{
    private const array COLUMNS = [
        'request_id' => 'VARCHAR(255) DEFAULT NULL',
        'answering_model' => 'VARCHAR(255) DEFAULT NULL',
        'cost_nano_credits' => 'BIGINT DEFAULT NULL',
    ];

    public function getDescription(): string
    {
        return 'Add the provider receipt to recommendation_run_log: request id, answering model, cost (#1345)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('recommendation_run_log')->hasColumn('request_id'),
            'recommendation_run_log.request_id already exists.',
        );

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE recommendation_run_log ' . implode(', ', array_map(
                static fn (string $column, string $definition): string => \sprintf('ADD %s %s', $column, $definition),
                array_keys(self::COLUMNS),
                self::COLUMNS,
            )));

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            foreach (self::COLUMNS as $column => $definition) {
                $this->addSql(\sprintf('ALTER TABLE recommendation_run_log ADD COLUMN %s %s', $column, $definition));
            }

            return;
        }

        throw new \RuntimeException('Unsupported database platform for the run log receipt migration.');
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            $this->addSql(\sprintf('ALTER TABLE recommendation_run_log DROP COLUMN %s', $column));
        }
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
