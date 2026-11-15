<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\GermanLexicon\GermanLexiconInterface;

/** A German -t or -et verb form takes its infinitive's stem, unless the word is a noun (#2230). */
final readonly class GermanVerbStems
{
    private const array ENDINGS = ['', 'et', 't'];

    public function __construct(private GermanLexiconInterface $lexicon)
    {
    }

    public function stemOf(string $stem, string $word): string
    {
        if ($this->lexicon->isNoun(mb_convert_case($word, \MB_CASE_FOLD, 'UTF-8'))) {
            return $stem;
        }

        foreach (self::ENDINGS as $ending) {
            $presentStem = $this->withoutEnding($stem, $ending);
            if ($presentStem !== null && $this->lexicon->isVerbStem($presentStem)) {
                return $this->lexicon->infinitiveStemOf($presentStem);
            }
        }

        return $stem;
    }

    private function withoutEnding(string $stem, string $ending): ?string
    {
        if ($ending === '') {
            return $stem;
        }

        return str_ends_with($stem, $ending) ? substr($stem, 0, -\strlen($ending)) : null;
    }
}
