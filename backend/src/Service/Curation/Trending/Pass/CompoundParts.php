<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

/** A compound's parts link it to other words, never again to the compound itself (#2230). */
final class CompoundParts
{
    /** @var array<int, true> */
    private array $compoundStems = [];

    /** @var array<int, array<int, true>> a part's stem id => the stem ids of the compounds holding it */
    private array $compoundsWithPart = [];

    /** @var array<int, array<int, true>> entry id => the stem ids of its compounds, for an entry holding any */
    private array $compoundsOf = [];

    /** @var array<int, array<int, true>> stem id => the entries holding it only as a part of their compounds */
    private array $partOnlyHoldersOf = [];

    /** @param list<int> $partStemIds */
    public function addCompound(int $compoundStemId, array $partStemIds): void
    {
        foreach ($partStemIds as $partStemId) {
            $this->compoundStems[$compoundStemId] = true;
            $this->compoundsWithPart[$partStemId][$compoundStemId] = true;
        }
    }

    public function isCompound(int $stemId): bool
    {
        return isset($this->compoundStems[$stemId]);
    }

    /**
     * For a stem first found a compound after some entries holding it were added.
     *
     * @param list<int> $entryIds
     */
    public function holdCompoundIn(int $compoundStemId, array $entryIds): void
    {
        foreach ($entryIds as $entryId) {
            if (!$this->isOnlyAPartIn($entryId, $compoundStemId)) {
                $this->compoundsOf[$entryId][$compoundStemId] = true;
            }
        }
    }

    /**
     * @param list<int> $stemIds
     * @param list<int> $wordStemIds the stems of its words themselves, not of their parts
     */
    public function addEntry(int $entryId, array $stemIds, array $wordStemIds): void
    {
        $compoundStemIds = array_keys(array_intersect_key(array_fill_keys($wordStemIds, true), $this->compoundStems));
        foreach ($compoundStemIds as $compoundStemId) {
            $this->compoundsOf[$entryId][$compoundStemId] = true;
        }
        foreach (array_diff($stemIds, $wordStemIds) as $partStemId) {
            $this->partOnlyHoldersOf[$partStemId][$entryId] = true;
        }
    }

    public function isOnlyAPartIn(int $entryId, int $stemId): bool
    {
        return isset($this->partOnlyHoldersOf[$stemId][$entryId]);
    }

    /**
     * @param array<int, float> $holders earlier entry id => the part's place weight there
     *
     * @return array<int, float> the holders with which the part links more than a compound they share
     */
    public function holdersLinkedBeyondASharedCompound(array $holders, int $partStemId, int $entryId): array
    {
        if (!isset($this->partOnlyHoldersOf[$partStemId])) {
            return $holders;
        }
        $compoundStemIds = $this->compoundsGiving($partStemId, $entryId);
        $linked = $compoundStemIds === [] ? $holders : array_filter(
            $holders,
            fn (int $holderId): bool => !$this->holdsAll($holderId, $compoundStemIds),
            \ARRAY_FILTER_USE_KEY,
        );
        foreach (array_keys(array_intersect_key($linked, $this->partOnlyHoldersOf[$partStemId])) as $holderId) {
            if ($this->holdsAll($entryId, $this->compoundsGiving($partStemId, $holderId))) {
                unset($linked[$holderId]);
            }
        }

        return $linked;
    }

    /** @return array<int, true> the entry's compounds holding the part; none when the entry holds it as a word too */
    private function compoundsGiving(int $partStemId, int $entryId): array
    {
        if (!$this->isOnlyAPartIn($entryId, $partStemId)) {
            return [];
        }

        return array_intersect_key($this->compoundsOf[$entryId] ?? [], $this->compoundsWithPart[$partStemId]);
    }

    /** @param array<int, true> $compoundStemIds */
    private function holdsAll(int $entryId, array $compoundStemIds): bool
    {
        return $compoundStemIds !== [] && array_diff_key($compoundStemIds, $this->compoundsOf[$entryId] ?? []) === [];
    }
}
