<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Drops MySQL collation names from generated schemas on every other platform.
 *
 * WHY THIS EXISTS. `UserIdentity::$providerUserId` must compare
 * case-sensitively, which on MySQL means pinning `utf8mb4_bin` in the mapping.
 * But an ORM column option is platform-blind: Doctrine emits it for whatever
 * platform the schema is generated against, and SQLite knows only three
 * collations (BINARY, NOCASE, RTRIM). It does not ignore an unknown name — it
 * fails hard, `SQLSTATE[HY000]: no such collation sequence: utf8mb4_bin`, at
 * CREATE TABLE. Since tests/bootstrap.php builds every test schema from ORM
 * metadata via doctrine:schema:create, an unguarded collation option takes the
 * entire SQLite suite down on the first table, before a single test runs.
 *
 * So the collation has to be MySQL-only, and there is no way to say that in a
 * `#[ORM\Column]` attribute. This is the seam Doctrine provides instead:
 * postGenerateSchemaTable fires inside SchemaTool::getSchemaFromMetadata(),
 * the single funnel through which schema:create, schema:update and
 * schema:validate all obtain the mapping's view of a table. Adjusting here
 * therefore keeps all three consistent rather than fixing one and skewing the
 * others.
 *
 * WHY STRIPPING IS SAFE, and not a silent loss of the guarantee. The collation
 * exists to make MySQL behave the way SQLite already does: SQLite's default
 * BINARY collation is byte-exact and case-sensitive. Removing the option there
 * leaves the column with precisely the semantics the option was added to
 * obtain. The two platforms converge on the same behaviour by opposite routes
 * — MySQL because it is told to, SQLite because it always did. The test
 * App\Tests\Repository\UserIdentityRepositoryTest::testSubjectLookupIsCaseSensitive
 * is what actually holds that convergence honest, and it runs on both legs.
 *
 * This is also what lets Version20260721181500's SQLite branch be an honest
 * no-op: with the option stripped, the migrated SQLite schema and the mapping
 * agree, so `doctrine:schema:validate` passes on both legs of the CI matrix.
 * Without this listener it would pass on neither — SQLite could not build a
 * schema at all, and the MySQL leg's validate is the only check that the
 * migration's COLLATE clause matches what the mapping asks for.
 *
 * SCOPE. Deliberately keyed on the `utf8mb4_` prefix rather than stripping
 * every collation. A collation SQLite genuinely understands should survive
 * untouched, and a future column that wants NOCASE must not be silently
 * flattened to BINARY by a listener written for a different problem.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final readonly class MySqlCollationSchemaListener
{
    /**
     * MySQL collation names carry their character set as a prefix. Matching on
     * that is what keeps this listener from touching a portable collation.
     */
    private const MYSQL_COLLATION_PREFIX = 'utf8mb4_';

    /**
     * The event args expose the metadata, the schema and the table, but not the
     * platform the schema is being generated for — so the connection is the
     * only route to it. Injecting it is safe despite the apparent cycle
     * (connection owns the event manager owns this listener): DoctrineBundle
     * registers listeners by service id in a ContainerAwareEventManager, so
     * this class is not instantiated until the event actually fires, which is
     * during a console schema command and never during boot.
     */
    public function __construct(private Connection $connection)
    {
    }

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
