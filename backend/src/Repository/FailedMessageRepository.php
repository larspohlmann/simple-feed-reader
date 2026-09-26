<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/** The failure transport's table, which `auto_setup` creates only on the first failed delivery. */
final readonly class FailedMessageRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function deleteFailedBefore(\DateTimeImmutable $cutoff): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = :queue AND created_at < :cutoff',
                ['queue' => 'failed', 'cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // No table yet: this stack has never failed a message, so there is nothing to purge.
        }
    }
}
