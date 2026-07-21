<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Records one entry per execution. See QueryRecorder.
 *
 * Bound parameter values are deliberately not captured — only the SQL text —
 * so nothing this holds can be a password, token or login code.
 *
 * @internal
 */
final class QueryRecorderStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly QueryRecorder $recorder,
        private readonly string $sql,
    ) {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $this->recorder->record($this->sql);

        return parent::execute();
    }
}
