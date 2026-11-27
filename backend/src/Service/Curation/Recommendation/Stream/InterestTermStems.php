<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTerm;
use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;
use App\Service\Curation\Recommendation\Stream\Model\TermStemModel;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\WordEquivalents;

final readonly class InterestTermStems
{
    public const float PART_WEIGHT_SHARE = 0.5;

    public function __construct(private WordEquivalents $equivalents, private StopWordStems $stopWordStems)
    {
    }

    /**
     * @param list<InterestTermModel> $terms
     *
     * @return list<TermStemModel> one per language and stem, at its highest weight
     */
    public function buildTermStems(array $terms): array
    {
        $stems = [];
        foreach ($terms as $term) {
            foreach ([$term->language, null] as $language) {
                array_push($stems, ...$this->readStems($term, $language));
            }
        }

        return self::keepHighestWeights($stems);
    }

    /**
     * @param list<TermStemModel> $stems
     *
     * @return list<TermStemModel>
     */
    private static function keepHighestWeights(array $stems): array
    {
        $highest = [];
        foreach ($stems as $stem) {
            $key = InterestTermsModel::languageKey($stem->language) . ':' . $stem->stem;
            if (!isset($highest[$key]) || $stem->weight > $highest[$key]->weight) {
                $highest[$key] = $stem;
            }
        }

        return array_values($highest);
    }

    /** @return list<TermStemModel> */
    private function readStems(InterestTermModel $term, ?FeedLanguage $language): array
    {
        $stems = [];
        foreach ($this->equivalents->canonicalizeWords($term->phrase) as [$word, $bare]) {
            foreach ($this->equivalents->stemWordAndParts($word, $bare, $language) as $position => $stem) {
                if (!$this->isStorable($stem, $language)) {
                    continue;
                }
                $weight = $position === 0 ? $term->weight : $term->weight * self::PART_WEIGHT_SHARE;
                $stems[] = new TermStemModel($language, $stem, $weight);
            }
        }

        return $stems;
    }

    private function isStorable(string $stem, ?FeedLanguage $language): bool
    {
        return mb_strlen($stem) <= InterestTerm::STEM_MAX_LENGTH
            && !$this->stopWordStems->isStopWordStem($stem, $language);
    }
}
