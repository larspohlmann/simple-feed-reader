<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming;

use App\Service\Foundation\Stemming\GermanLexicon\GermanLexiconInterface;
use App\Service\Foundation\Stemming\Model\CompoundSplitModel;
use App\Service\Foundation\Stemming\Model\GermanLexiconEntryModel;
use App\Service\Foundation\Stemming\Pass\CompoundSplitSearch;

final readonly class GermanCompoundSplitter
{
    public const int MIN_PART_LENGTH = 3;
    public const int MAX_PARTS = 4;
    public const int MAX_PARTS_OF_AN_UNKNOWN_WORD = 2;
    public const array LINKING_SUFFIXES = ['s', 'es', 'n', 'en', 'er', 'e', 'ens'];
    public const string RESTORED_ENDING = 'e';

    public function __construct(private GermanLexiconInterface $lexicon)
    {
    }

    /** @return list<string> the lexicon forms of the word's parts, or none when the word is no compound */
    public function splitParts(string $word): array
    {
        if (mb_strlen($word) < 2 * self::MIN_PART_LENGTH) {
            return [];
        }
        $whole = $this->lexicon->findEntry($word);
        if (null === $whole) {
            return $this->findBestSplit($word, self::MAX_PARTS_OF_AN_UNKNOWN_WORD)?->parts() ?? [];
        }
        $split = $this->findBestSplit($word, self::MAX_PARTS);
        if (null === $split || $this->staysWhole($whole, $split)) {
            return [];
        }

        return $split->parts();
    }

    private function findBestSplit(string $word, int $maxParts): ?CompoundSplitModel
    {
        return (new CompoundSplitSearch($this->lexicon, $word, $maxParts))->findBestSplit();
    }

    private function staysWhole(GermanLexiconEntryModel $whole, CompoundSplitModel $split): bool
    {
        $wholeClass = $whole->frequencyClass;

        return $split->hasMeanClassOfAtLeast($wholeClass) || $split->hasPartRarerThan($wholeClass);
    }
}
