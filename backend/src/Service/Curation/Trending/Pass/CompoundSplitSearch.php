<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Service\Curation\Trending\GermanCompoundSplitter;
use App\Service\Curation\Trending\GermanLexicon\GermanLexiconInterface;
use App\Service\Curation\Trending\Model\CompoundSplitModel;

final class CompoundSplitSearch
{
    private const string VOWEL = '/[aeiouyäöü]/u';

    /** @var list<string> */
    private readonly array $letters;

    /** @var array<int, array<int, CompoundSplitModel>> the best split of each suffix, by its offset and part count */
    private array $splitsByOffset = [];

    public function __construct(private readonly GermanLexiconInterface $lexicon, string $word)
    {
        $this->letters = mb_str_split($word);
    }

    /** @return list<CompoundSplitModel> the best split of the word for each part count from two up */
    public function splits(): array
    {
        return array_values($this->compoundsFrom(0));
    }

    /** @return array<int, CompoundSplitModel> */
    private function splitsFrom(int $offset): array
    {
        if (!isset($this->splitsByOffset[$offset])) {
            $head = $this->headFrom($offset);
            $this->splitsByOffset[$offset] = (null === $head ? [] : [1 => $head]) + $this->compoundsFrom($offset);
        }

        return $this->splitsByOffset[$offset];
    }

    /** @return array<int, CompoundSplitModel> */
    private function compoundsFrom(int $offset): array
    {
        $best = [];
        $lastEnd = count($this->letters) - GermanCompoundSplitter::MIN_PART_LENGTH;
        for ($end = $offset + GermanCompoundSplitter::MIN_PART_LENGTH; $end <= $lastEnd; ++$end) {
            foreach ($this->compoundsSplitAt($offset, $end) as $candidate) {
                $partCount = $candidate->partCount();
                if (!isset($best[$partCount]) || $candidate->beats($best[$partCount])) {
                    $best[$partCount] = $candidate;
                }
            }
        }

        return $best;
    }

    /** @return list<CompoundSplitModel> */
    private function compoundsSplitAt(int $offset, int $end): array
    {
        $tails = array_filter(
            $this->splitsFrom($end),
            static fn (CompoundSplitModel $tail): bool => $tail->partCount() < GermanCompoundSplitter::MAX_PARTS,
        );
        $modifier = [] === $tails ? null : $this->modifierBetween($offset, $end);
        if (null === $modifier) {
            return [];
        }

        return array_values(array_map(
            static fn (CompoundSplitModel $tail): CompoundSplitModel => $tail->prependedBy($modifier),
            $tails,
        ));
    }

    private function headFrom(int $offset): ?CompoundSplitModel
    {
        $form = implode('', array_slice($this->letters, $offset));
        $part = $this->partOf($form);

        return null !== $part && $this->lexicon->isNoun($form) ? $part : null;
    }

    private function modifierBetween(int $offset, int $end): ?CompoundSplitModel
    {
        $segment = implode('', array_slice($this->letters, $offset, $end - $offset));
        $best = null;
        foreach ($this->linkingVariantsOf($segment) as $variant) {
            $part = $this->partOf($variant);
            if (null !== $part && (null === $best || $part->beats($best))) {
                $best = $part;
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function linkingVariantsOf(string $segment): array
    {
        $unlinked = array_map(
            static fn (string $suffix): string => substr($segment, 0, -strlen($suffix)),
            array_filter(
                GermanCompoundSplitter::LINKING_SUFFIXES,
                static fn (string $suffix): bool => str_ends_with($segment, $suffix),
            ),
        );

        return [$segment, ...array_values($unlinked), $segment . GermanCompoundSplitter::RESTORED_ENDING];
    }

    /** A part needs a vowel and must be no rarer than the median noun of its length, which keeps out junk forms. */
    private function partOf(string $form): ?CompoundSplitModel
    {
        $length = mb_strlen($form);
        if ($length < GermanCompoundSplitter::MIN_PART_LENGTH || 1 !== preg_match(self::VOWEL, $form)) {
            return null;
        }
        $frequencyClass = $this->lexicon->frequencyClassOf($form);
        if ($frequencyClass > $this->lexicon->medianNounClassOfLength($length) || !$this->lexicon->isKnown($form)) {
            return null;
        }

        return CompoundSplitModel::single($form, $frequencyClass);
    }
}
