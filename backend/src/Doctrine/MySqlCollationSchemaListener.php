<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Strips MySQL collations from generated schemas on every other platform: SQLite fails CREATE TABLE on `utf8mb4_bin`,
 * and its own BINARY default already is the case-sensitive match the MySQL pin buys (UserIdentityRepositoryTest).
 * `doctrine:schema:validate` passes on SQLite only because of this listener.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final readonly class MySqlCollationSchemaListener
{
    /** MySQL's own collations only: a portable one, such as a future NOCASE column, must survive on SQLite. */
    private const string MYSQL_COLLATION_PREFIX = 'utf8mb4_';

    /**
     * The connection is the only route to the target platform. No boot cycle: DoctrineBundle instantiates a listener
     * lazily, when a console schema command fires the event.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        foreach ($event->getClassTable()->getColumns() as $column) {
            $collation = $column->getCollation();

            if (null === $collation || !str_starts_with($collation, self::MYSQL_COLLATION_PREFIX)) {
                continue;
            }

            $options = $column->getPlatformOptions();
            unset($options['collation']);
            $column->setPlatformOptions($options);
        }
    }
}
