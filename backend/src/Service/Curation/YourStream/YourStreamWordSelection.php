<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\Model\StreamWordModel;
use App\Service\Curation\YourStream\Pass\InterestTally;
use App\Service\Curation\YourStream\Pass\RarityTally;

/**
 * Keeps, per language, the stems whose share of the interests stands out against their share of the background,
 * each smoothed so a stem absent from either still compares.
 */
final readonly class YourStreamWordSelection
{
    public const int MIN_ARTICLES = 2;
    public const int WORDS_PER_LANGUAGE = 60;
    public const float MIN_LIFT = 2.0;
    public const int SEARCH_ARTICLES = 3;
    public const int COUNTED_ENTRIES_FLOOR = 500;

    private const float SMOOTHING = 0.5;
    private const int WEIGHT_STEPS = 10;

    /** @return list<StreamWordModel> by language in FeedLanguage::cases() order, the cut form last, heaviest first */
    public function pickWords(InterestTally $interests, RarityTally $background): array
    {
        $words = [];
        foreach ($interests->collectLanguageKeys() as $languageKey) {
            array_push($words, ...self::rescaleWeights($this->rankWords($interests, $background, $languageKey)));
        }

        return $words;
    }

    /** @return list<StreamWordModel> weighed by their raw score */
    private function rankWords(InterestTally $interests, RarityTally $background, string $languageKey): array
    {
        $language = FeedLanguage::tryFrom($languageKey);
        $articles = $interests->countArticles($languageKey);
        $counted = max($background->countEntries($languageKey), self::COUNTED_ENTRIES_FLOOR);
        $words = [];
        foreach ($interests->listStems($languageKey) as $stem) {
            if ($interests->countArticlesWith($languageKey, $stem) < self::MIN_ARTICLES) {
                continue;
            }
            $documents = $background->countDocuments($languageKey, $stem);
            $interestShare = ($interests->readSupport($languageKey, $stem) + self::SMOOTHING) / ($articles + 1);
            $lift = $interestShare / (($documents + self::SMOOTHING) / ($counted + 1));
            if ($lift < self::MIN_LIFT) {
                continue;
            }
            $word = $interests->readDisplayWord($languageKey, $stem);
            $rarity = log(($counted + 2) / ($documents + 1));
            $words[] = new StreamWordModel($language, $stem, $word, $interestShare * log($lift), $rarity);
        }

        return self::keepTopScores($words);
    }

    /**
     * @param list<StreamWordModel> $words
     *
     * @return list<StreamWordModel> highest first, equal scores by stem
     */
    private static function keepTopScores(array $words): array
    {
        usort(
            $words,
            static fn (StreamWordModel $left, StreamWordModel $right): int => [$right->weight, $left->stem]
                <=> [$left->weight, $right->stem],
        );

        return \array_slice($words, 0, self::WORDS_PER_LANGUAGE);
    }

    /**
     * @param list<StreamWordModel> $ranked
     *
     * @return list<StreamWordModel> on the 0.1–1.0 scale, in tenths
     */
    private static function rescaleWeights(array $ranked): array
    {
        if ($ranked === []) {
            return [];
        }
        $topScore = $ranked[0]->weight;

        return array_map(
            static fn (StreamWordModel $word): StreamWordModel => $word->withWeight(
                max(1.0, round(self::WEIGHT_STEPS * $word->weight / $topScore)) / self::WEIGHT_STEPS,
            ),
            $ranked,
        );
    }
}
