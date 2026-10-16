<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Entity\RetentionWindows;

final readonly class RetentionSettingsJson
{
    /** @return array{entryRetentionDays: int, socialRetentionDays: int} */
    public static function from(RetentionWindows $windows): array
    {
        return [
            'entryRetentionDays' => $windows->entryDays,
            'socialRetentionDays' => $windows->socialDays,
        ];
    }
}
