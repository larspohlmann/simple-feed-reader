<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Entity\EntryChapter;

/** An item's chapters: the ones it carries inline, or else the chapters file it links. */
final readonly class ParsedChaptersModel
{
    /** @param list<EntryChapter> $chapters */
    public function __construct(
        public array $chapters = [],
        public ?string $url = null,
    ) {
    }
}
