<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Service\Curation\Trending\GermanLexicon\GermanLexiconInterface;
use App\Service\Curation\Trending\Model\CompoundSplitModel;
use App\Service\Curation\Trending\Pass\CompoundSplitSearch;

final readonly class GermanCompoundSplitter
{
    public const int MIN_PART_LENGTH = 3;
    public const int MAX_PARTS = 4;
    public const array LINKING_SUFFIXES = ['s', 'es', 'n', 'en', 'er', 'e', 'ens'];
    public const string RESTORED_ENDING = 'e';

    public function __construct(private GermanLexiconInterface $lexicon)
    {
    }

    /** @return list<string> the lexicon forms of the word's parts, or none when the word is no compound */
    public function partsOf(string $word): array
    {
        if (mb_strlen($word) < 2 * self::MIN_PART_LENGTH) {
            return [];
        }
        $best = CompoundSplitModel::single($word, $this->lexicon->frequencyClassOf($word));
        foreach ((new CompoundSplitSearch($this->lexicon, $word))->splits() as $split) {
            if ($split->beats($best)) {
                $best = $split;
            }
        }

        return $best->isCompound() ? $best->parts() : [];
    }
}
