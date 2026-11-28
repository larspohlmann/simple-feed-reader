<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\WordEquivalents;
use App\Service\Foundation\Text\StopWords\StopWordsInterface;

final readonly class SearchTermStems
{
    public const float PART_WEIGHT_SHARE = 0.5;

    public function __construct(
        private WordEquivalents $equivalents,
        private StopWordStems $stopWordStems,
        private StopWordsInterface $stopWords,
    ) {
    }

    /** @return array<string, float> each stem with its share of the term: a whole word's, else a compound part's */
    public function collectStems(string $term, ?FeedLanguage $language): array
    {
        $words = $this->equivalents->canonicalizeWords($term);
        if ($this->isOnlyStopWordsOfOneLanguage($words)) {
            return [];
        }
        $shares = [];
        foreach ($words as [$word, $bare]) {
            foreach ($this->equivalents->stemWordAndParts($word, $bare, $language) as $position => $stem) {
                if ($this->isStorable($stem, $language)) {
                    $share = $position === 0 ? 1.0 : self::PART_WEIGHT_SHARE;
                    $shares[$stem] = max($shares[$stem] ?? 0.0, $share);
                }
            }
        }

        return $shares;
    }

    public function isSingleWord(string $term): bool
    {
        return \count($this->equivalents->canonicalizeWords($term)) === 1;
    }

    /**
     * A term is typed in one language but stemmed in all: "der die das" must not become English words.
     *
     * @param list<array{string, string}> $words
     */
    private function isOnlyStopWordsOfOneLanguage(array $words): bool
    {
        return $words !== [] && array_any(
            FeedLanguage::cases(),
            fn (FeedLanguage $language): bool => array_all(
                $words,
                fn (array $word): bool => $this->stopWords->isStopWord($word[0], $language),
            ),
        );
    }

    private function isStorable(string $stem, ?FeedLanguage $language): bool
    {
        return mb_strlen($stem) <= YourStreamWord::STEM_MAX_LENGTH
            && !$this->stopWordStems->isStopWordStem($stem, $language);
    }
}
