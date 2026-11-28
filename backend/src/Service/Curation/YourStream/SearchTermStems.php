<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\InterestTerm;
use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\WordEquivalents;

final readonly class SearchTermStems
{
    public const float PART_WEIGHT_SHARE = 0.5;

    public function __construct(private WordEquivalents $equivalents, private StopWordStems $stopWordStems)
    {
    }

    /**
     * A term is typed in one language but stemmed in all, so a stop word of any language is dropped first.
     *
     * @return array<string, float> each stem with its share of the term: a whole word's, else a compound part's
     */
    public function collectStems(string $term, ?FeedLanguage $language): array
    {
        $shares = [];
        foreach ($this->equivalents->canonicalizeWords($term) as [$word, $bare]) {
            if ($this->stopWordStems->isStopWordInAnyLanguage($word)) {
                continue;
            }
            foreach ($this->equivalents->stemWordAndParts($word, $bare, $language) as $position => $stem) {
                if ($this->isStorable($stem, $language)) {
                    $share = $position === 0 ? 1.0 : self::PART_WEIGHT_SHARE;
                    $shares[$stem] = max($shares[$stem] ?? 0.0, $share);
                }
            }
        }

        return $shares;
    }

    private function isStorable(string $stem, ?FeedLanguage $language): bool
    {
        return mb_strlen($stem) <= InterestTerm::STEM_MAX_LENGTH
            && !$this->stopWordStems->isStopWordStem($stem, $language);
    }
}
