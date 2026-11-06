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
            'startedAt' => $status->currentRun?->startedAt?->format(\DATE_ATOM),
            'finishedAt' => $status->currentRun?->finishedAt?->format(\DATE_ATOM),
            'exitCode' => $status->currentRun?->exitCode,
            'log' => $status->log,
        ];
    }
}
