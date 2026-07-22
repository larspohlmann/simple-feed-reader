<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Records the SQL a connection runs. See QueryRecorder.
 *
 * @internal
 */
final class QueryRecorderConnection extends AbstractConnectionMiddleware
{
    public function __construct(DriverConnection $connection, private readonly QueryRecorder $recorder)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        // Recorded on execute(), not here: the ORM prepares once and may
        // execute the same statement repeatedly, and it is the executions an
        // N+1 multiplies.
        return new QueryRecorderStatement(parent::prepare($sql), $this->recorder, $sql);
    }

    public function query(string $sql): Result
    {
        $this->recorder->record($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int|string
    {
        $this->recorder->record($sql);

        return parent::exec($sql);
    }
}
