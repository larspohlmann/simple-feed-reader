<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\SelfUpdate\Model\SelfUpdateStatusModel;

final readonly class SelfUpdateStatusJson
{
    /**
     * @return array{
     *     updaterPresent: bool,
     *     phase: string,
     *     startedAt: ?string,
     *     finishedAt: ?string,
     *     exitCode: ?int,
     *     log: string,
     * }
     */
    public static function of(SelfUpdateStatusModel $status): array
    {
        return [
            'updaterPresent' => $status->updaterPresent,
            'phase' => $status->phase->value,
            'startedAt' => $status->lastRun?->startedAt?->format(\DATE_ATOM),
            'finishedAt' => $status->lastRun?->finishedAt?->format(\DATE_ATOM),
            'exitCode' => $status->lastRun?->exitCode,
            'log' => $status->log,
        ];
    }
}
