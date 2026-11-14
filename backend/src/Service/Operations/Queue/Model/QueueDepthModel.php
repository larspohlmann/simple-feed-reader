<?php

declare(strict_types=1);

namespace App\Service\Operations\Queue\Model;

final readonly class QueueDepthModel
{
    public function __construct(
        public QueueName $queue,
        public QueueBacklogModel $backlog,
    ) {
    }
}
