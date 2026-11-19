<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

final class CuratedBlueskyList
{
    private const string PURPOSE = 'app.bsky.graph.defs#curatelist';

    private function __construct()
    {
    }

    public static function isPurpose(?string $purpose): bool
    {
        return self::PURPOSE === $purpose;
    }
}
