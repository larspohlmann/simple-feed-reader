<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * The evidence a GatedMediaPlaceholderInterface needs to replace a gated media
 * region: the source article URL to link out to, and the poster image URL to
 * show in its place (null when the page reported none).
 */
final readonly class GatedMediaContext
{
    public function __construct(
        public string $sourceUrl,
        public ?string $posterUrl,
    ) {
    }
}
