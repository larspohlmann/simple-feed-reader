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

    /** @var array<int, ?CompoundSplitModel> the best split of each suffix, by its offset */
    private array $bestByOffset = [];

    public function __construct(
        private readonly GermanLexiconInterface $lexicon,
        string $word,
        private readonly int $maxParts,
    ) {
        $this->letters = mb_str_split($word);
    }

    public function findBestSplit(): ?CompoundSplitModel
    {
        return $this->findBestCompoundFrom(0);
    }

    private function findBestFrom(int $offset): ?CompoundSplitModel
    {
        if (!array_key_exists($offset, $this->bestByOffset)) {
            $suffix = implode('', array_slice($this->letters, $offset));
            $this->bestByOffset[$offset] = $this->findNounPart($suffix) ?? $this->findBestCompoundFrom($offset);
        }

        return $this->bestByOffset[$offset];
    }

    private function findBestCompoundFrom(int $offset): ?CompoundSplitModel
    {
        $best = null;
        $lastEnd = count($this->letters) - GermanCompoundSplitter::MIN_PART_LENGTH;
        for ($end = $offset + GermanCompoundSplitter::MIN_PART_LENGTH; $end <= $lastEnd; ++$end) {
            $candidate = $this->findCompoundSplit($offset, $end);
            if (null !== $candidate && (null === $best || $candidate->beats($best))) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function findCompoundSplit(int $offset, int $end): ?CompoundSplitModel
    {
        $tail = $this->findBestFrom($end);
        if (null === $tail || $tail->partCount() >= $this->maxParts) {
            return null;
        }
        $modifier = $this->findModifierBetween($offset, $end);

        return null === $modifier ? null : $tail->prependedBy($modifier);
    }

    private function findModifierBetween(int $offset, int $end): ?CompoundSplitModel
    {
        $segment = implode('', array_slice($this->letters, $offset, $end - $offset));
        $best = null;
        foreach ($this->buildLinkingVariants($segment) as $variant) {
            $part = $this->findNounPart($variant);
            if (null !== $part && (null === $best || $part->isMoreFrequentThan($best))) {
                $best = $part;
            }
        }

        return $best;
    }

    /** @return list<string> */
    private function buildLinkingVariants(string $segment): array
    {
        $unlinked = array_map(
            static fn (string $suffix): string => substr($segment, 0, -strlen($suffix)),
            array_filter(
                GermanCompoundSplitter::LINKING_SUFFIXES,
                static fn (string $suffix): bool => str_ends_with($segment, $suffix),
            ),
        );

        $variants = [$segment, ...array_values($unlinked), $segment . GermanCompoundSplitter::RESTORED_ENDING];
        usort($variants, static fn (string $left, string $right): int => strlen($left) <=> strlen($right));

        return $variants;
    }

    private function findNounPart(string $form): ?CompoundSplitModel
    {
        if (mb_strlen($form) < GermanCompoundSplitter::MIN_PART_LENGTH || 1 !== preg_match(self::VOWEL, $form)) {
            return null;
        }

        $entry = $this->lexicon->findEntry($form);

        return true === $entry?->isNoun() ? CompoundSplitModel::single($form, $entry->frequencyClass) : null;
    }
}
