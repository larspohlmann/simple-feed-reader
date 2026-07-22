<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Provider\DBALSchemaDiffProvider;
use Doctrine\Migrations\Provider\SchemaDiffProvider;

/**
 * Builds the eager schema-diff provider that replaces doctrine/migrations' lazy
 * default, silencing two deprecations that otherwise land in dev.log on every
 * `doctrine:migrations:migrate`. The full rationale lives in
 * config/services.yaml; the wiring that makes migrations use it lives in
 * config/packages/doctrine_migrations.yaml.
 *
 * A factory is required because DBALSchemaDiffProvider is constructed from the
 * connection's schema manager and database platform, and neither is a container
 * service — both are produced by method calls on the connection at runtime.
 * That is also why DependencyFactory::getSchemaDiffProvider() builds it by hand.
 *
 * The return type is the (\@internal) SchemaDiffProvider interface because that
 * is exactly the doctrine/migrations dependency we are overriding; coupling to
 * it is inherent to the override and is guarded by CI on every migrations bump.
 */
final class EagerSchemaDiffProviderFactory
{
    public static function create(Connection $connection): SchemaDiffProvider
    {
        return new DBALSchemaDiffProvider(
            $connection->createSchemaManager(),
            $connection->getDatabasePlatform(),
        );
    }
}
