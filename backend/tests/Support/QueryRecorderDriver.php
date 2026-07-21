<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Wraps every connection the driver hands out. See QueryRecorder.
 *
 * @internal
 */
final class QueryRecorderDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryRecorder $recorder)
    {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new QueryRecorderConnection(parent::connect($params), $this->recorder);
    }
}
