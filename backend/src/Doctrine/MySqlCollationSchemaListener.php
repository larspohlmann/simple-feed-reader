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
 * Drops MySQL collation names from generated schemas on every other platform.
 *
 * WHY: `UserIdentity::$providerUserId` must compare case-sensitively, which on
 * MySQL means pinning `utf8mb4_bin` in the mapping — but an ORM column option is
 * platform-blind, and SQLite (BINARY/NOCASE/RTRIM only) fails hard on an unknown
 * name (`no such collation sequence: utf8mb4_bin`) at CREATE TABLE. Since
 * tests/bootstrap.php builds every test schema from ORM metadata, an unguarded
 * option takes the whole SQLite suite down on the first table. A `#[ORM\Column]`
 * attribute can't express "MySQL-only", so this hooks postGenerateSchemaTable --
 * the single funnel schema:create, schema:update and schema:validate all use to
 * read a table's mapping -- to strip it there instead.
 *
 * SAFE TO STRIP: the collation exists only to make MySQL match SQLite's own
 * byte-exact, case-sensitive BINARY default, so removing it preserves the exact
 * semantics it was added for; the platforms converge by opposite routes.
 * UserIdentityRepositoryTest::testSubjectLookupIsCaseSensitive holds that
 * convergence honest on both legs, and it lets Version20260721181500's SQLite
 * branch be an honest no-op -- `doctrine:schema:validate` passes on both CI legs
 * only because of this listener.
 *
 * SCOPE: keyed on the `utf8mb4_` prefix, not every collation, so a collation
 * SQLite genuinely understands survives and a future NOCASE column is not
 * silently flattened to BINARY.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final readonly class MySqlCollationSchemaListener
{
    /**
     * MySQL collation names carry their character set as a prefix. Matching on
     * that is what keeps this listener from touching a portable collation.
     */
    private const string MYSQL_COLLATION_PREFIX = 'utf8mb4_';

    /**
     * The event args expose the metadata, schema and table, but not the target
     * platform — the connection is the only route to it. Injecting it is safe
     * despite the apparent cycle (connection owns the event manager owns this
     * listener): DoctrineBundle registers listeners by service id in a
     * ContainerAwareEventManager, so this class is not instantiated until the
     * event fires, during a console schema command, never during boot.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        foreach ($args->getClassTable()->getColumns() as $column) {
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
