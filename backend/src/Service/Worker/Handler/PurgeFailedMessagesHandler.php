<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Worker\Message\PurgeFailedMessages;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily housekeeping (#311): prune old rows out of the failure transport, so
 * a stuck worker cannot let `messenger_messages` grow without bound.
 * `auto_setup` creates the table lazily on the first failed delivery
 * (config/packages/doctrine.yaml hides it from the ORM-built schema), so a
 * stack that never failed a message has nothing to purge.
 */
#[AsMessageHandler]
final readonly class PurgeFailedMessagesHandler
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PurgeFailedMessages $message): void
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        try {
            $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = :queue AND created_at < :cutoff',
                ['queue' => 'failed', 'cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (TableNotFoundException) {
            // auto_setup creates the table on the first failed delivery; a
            // stack that never failed a message has nothing to purge.
        }
    }
}
