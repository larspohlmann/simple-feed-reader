<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\GermanLexicon\GermanLexiconInterface;

/** A German word ending in -t takes the stem of the infinitive its -et or -t form belongs to, unless it is a noun (#2230). */
final readonly class GermanVerbStems
{
    private const array ENDINGS = ['et', 't'];

    public function __construct(private GermanLexiconInterface $lexicon)
    {
    }

    public function stemOf(string $stem, string $word): string
    {
        $folded = mb_convert_case($word, \MB_CASE_FOLD, 'UTF-8');
        if (!str_ends_with($folded, 't') || $this->lexicon->isNoun($folded)) {
            return $stem;
        }

        $presentStem = $this->presentStemOf($stem);

        return $presentStem === null ? $stem : $this->lexicon->infinitiveStemOf($presentStem);
    }

    private function presentStemOf(string $stem): ?string
    {
        $own = $this->lexicon->isVerbStem($stem) ? $stem : null;
        foreach (self::ENDINGS as $ending) {
            if (!str_ends_with($stem, $ending)) {
                continue;
            }
            $shorter = substr($stem, 0, -\strlen($ending));
            if ($this->lexicon->isVerbStem($shorter) && $this->isMoreFrequentThan($shorter, $own)) {
                return $shorter;
            }
        }

        return $own;
    }

    private function isMoreFrequentThan(string $presentStem, ?string $rivalStem): bool
    {
        if ($rivalStem === null) {
            return true;
        }

        return $this->lexicon->infinitiveFrequencyClassOf($presentStem)
            < $this->lexicon->infinitiveFrequencyClassOf($rivalStem);
    }
}
