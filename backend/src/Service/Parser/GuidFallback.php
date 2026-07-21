<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class GuidFallback
{
    /**
     * Entries without a GUID get a stable synthetic one derived from link and
     * title, so re-fetches dedupe correctly.
     */
    public static function for(?string $guid, ?string $url, ?string $title): string
    {
        if ($guid !== null && $guid !== '') {
            return $guid;
        }

        return 'urn:sfr:' . hash('sha256', ($url ?? '') . '|' . ($title ?? ''));
    }
}
