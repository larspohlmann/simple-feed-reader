<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * One category a feed declared for an entry, as read from the document: the
 * label shown, and the taxonomy it belongs to when the feed named one (RSS
 * <category domain>, Atom <category scheme>). Normalization happens later.
 */
final readonly class ParsedCategory
{
    public function __construct(
        public string $label,
        public ?string $scheme = null,
    ) {
    }
}
