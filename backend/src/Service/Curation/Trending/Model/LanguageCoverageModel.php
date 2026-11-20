<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Enum\FeedLanguage;

/** How many window entries came from feeds of each language, and how many from feeds without one. */
final readonly class LanguageCoverageModel
{
    /** @param array<string, int> $entriesByLanguage keyed by the language's value */
    public function __construct(private array $entriesByLanguage, public int $entriesWithoutLanguage)
    {
    }

    public function readEntryCount(FeedLanguage $language): int
    {
        return $this->entriesByLanguage[$language->value] ?? 0;
    }
}
