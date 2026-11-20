<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

final class CompoundParts
{
    /** @var array<int, true> */
    private array $compoundStems = [];

    /** @var array<int, array<int, true>> a part's stem id => the stem ids of the compounds holding it */
    private array $compoundsWithPart = [];

    /** @var array<int, array<int, float>> entry id => the stem ids of its compounds => their place weight there */
    private array $compoundsOf = [];

    /** @var array<int, array<int, true>> stem id => the entries holding it only as a part of their compounds */
    private array $partOnlyHoldersOf = [];

    /** @param non-empty-list<int> $partStemIds */
    public function addCompound(int $compoundStemId, array $partStemIds): void
    {
        $this->compoundStems[$compoundStemId] = true;
        foreach ($partStemIds as $partStemId) {
            $this->compoundsWithPart[$partStemId][$compoundStemId] = true;
        }
    }

    public function isCompound(int $stemId): bool
    {
        return isset($this->compoundStems[$stemId]);
    }

    /** @param list<int> $entryIds */
    public function holdCompoundIn(int $compoundStemId, array $entryIds, float $placeWeight): void
    {
        foreach ($entryIds as $entryId) {
            $this->hold($entryId, $compoundStemId, $placeWeight);
        }
    }

    /** @param list<int> $partOnlyStemIds the stems no word of the entry holds, only a compound's parts */
    public function addEntry(int $entryId, array $partOnlyStemIds): void
    {
        foreach ($partOnlyStemIds as $partStemId) {
            $this->partOnlyHoldersOf[$partStemId][$entryId] = true;
        }
    }

    /**
     * After addEntry, once for each place.
     *
     * @param list<int> $stemIds
     */
    public function holdCompoundsAmong(int $entryId, array $stemIds, float $placeWeight): void
    {
        foreach ($stemIds as $stemId) {
            if ($this->isCompound($stemId)) {
                $this->hold($entryId, $stemId, $placeWeight);
            }
        }
    }

    public function isOnlyAPartIn(int $entryId, int $stemId): bool
    {
        return isset($this->partOnlyHoldersOf[$stemId][$entryId]);
    }

    /**
     * @param array<int, float> $holders earlier entry id => the stem's place weight there
     *
     * @return array<int, array{}|array{array<int, float>, array<int, float>}> each holder either entry holds the stem
     *     in as a part only => the compounds it comes through in this entry, then in the holder, with their place
     *     weight; none where it repeats a compound both hold
     */
    public function buildPartLinks(int $stemId, int $entryId, array $holders): array
    {
        if (!isset($this->partOnlyHoldersOf[$stemId])) {
            return [];
        }
        $givenHere = $this->compoundsGiving($stemId, $entryId);
        $partOnlyHolders = $this->partOnlyHoldersOf[$stemId];
        $links = [];
        $partHolders = $givenHere === [] ? array_intersect_key($holders, $partOnlyHolders) : $holders;
        foreach (array_keys($partHolders) as $holderId) {
            $throughHere = $this->filterUnshared($givenHere, $holderId);
            $throughThere = isset($partOnlyHolders[$holderId])
                ? $this->filterUnshared($this->compoundsGiving($stemId, $holderId), $entryId)
                : [];
            $links[$holderId] = null === $throughHere || null === $throughThere ? [] : [$throughHere, $throughThere];
        }

        return $links;
    }

    private function hold(int $entryId, int $compoundStemId, float $placeWeight): void
    {
        if (!$this->isOnlyAPartIn($entryId, $compoundStemId)) {
            $this->compoundsOf[$entryId][$compoundStemId] = $placeWeight;
        }
    }

    /** @return array<int, float> the entry's compounds holding the part; none when the entry holds it as a word too */
    private function compoundsGiving(int $partStemId, int $entryId): array
    {
        if (!$this->isOnlyAPartIn($entryId, $partStemId)) {
            return [];
        }

        return array_intersect_key($this->compoundsOf[$entryId] ?? [], $this->compoundsWithPart[$partStemId]);
    }

    /**
     * @param array<int, float> $compounds
     *
     * @return ?array<int, float> the compounds the other entry does not hold; null when it holds every one
     */
    private function filterUnshared(array $compounds, int $otherEntryId): ?array
    {
        if ($compounds === [] || !isset($this->compoundsOf[$otherEntryId])) {
            return $compounds;
        }
        $unshared = array_diff_key($compounds, $this->compoundsOf[$otherEntryId]);

        return $unshared === [] ? null : $unshared;
    }
}
