<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Queue\Model\QueueDepthModel;

final readonly class QueueDepthsJson
{
    /**
     * @param list<QueueDepthModel> $depths
     *
     * @return array{queues: list<array{queue: string, waiting: int, oldestQueuedAt: ?string}>}
     */
    public static function from(array $depths): array
    {
        return ['queues' => array_map(self::depth(...), $depths)];
    }

    /** @return array{queue: string, waiting: int, oldestQueuedAt: ?string} */
    private static function depth(QueueDepthModel $depth): array
    {
        return [
            'queue' => $depth->queue->value,
            'waiting' => $depth->backlog->waiting,
            'oldestQueuedAt' => $depth->backlog->oldestQueuedAt?->format(\DATE_ATOM),
        ];
    }
}
