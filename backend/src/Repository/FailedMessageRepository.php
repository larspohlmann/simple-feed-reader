<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Queue\Model\QueueBacklogModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/** The failure transport's table, which `auto_setup` creates only on the first failed delivery. */
final readonly class FailedMessageRepository
{
    private const string FAILURE_QUEUE = 'failed';

    public function __construct(private Connection $connection)
    {
    }

    public function deleteFailedBefore(\DateTimeImmutable $cutoff): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = :queue AND created_at < :cutoff',
                ['queue' => self::FAILURE_QUEUE, 'cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // No table yet: this stack has never failed a message, so there is nothing to purge.
        }
    }

    public function backlog(): QueueBacklogModel
    {
        try {
            /** @var array{waiting: int|string, oldest: ?string} $row */
            $row = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS waiting, MIN(created_at) AS oldest '
                . 'FROM messenger_messages WHERE queue_name = :queue',
                ['queue' => self::FAILURE_QUEUE],
            );
        } catch (TableNotFoundException) {
            return QueueBacklogModel::none();
        }

        return QueueBacklogModel::fromAggregate($row['waiting'], $row['oldest']);
    }
}
