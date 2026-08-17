<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Backup\RestoreResult;

/**
 * What a restore actually loaded, counted as rows written.
 */
final readonly class RestoreResultJson
{
    /**
     * @return array<string, mixed>
     */
    public static function from(RestoreResult $result): array
    {
        return [
            'loaded' => [
                'tags' => $result->tags,
                'feeds' => $result->feeds,
                'subscriptions' => $result->subscriptions,
                'entries' => $result->entries,
                'entryStates' => $result->entryStates,
            ],
        ];
    }
}
