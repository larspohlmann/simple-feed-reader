<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineEquivalents;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Curation\Trending\Model\LanguageCoverageModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;

/** Lookups cache per feed and entry: add every entry before asking for a weight. */
final class HeadlineWindow
{
    private const float HEADLINE_WEIGHT = 1.0;
    private const float BODY_WEIGHT = 0.5;

    /** @var array<string, int> */
    private array $stemIds = [];

    /** @var array<int, int> stem id => entries holding it */
    private array $documentFrequencies = [];

    /** @var array<int, list<int>> entry id => headline stem ids */
    private array $headlineStems = [];

    /** @var array<int, list<int>> entry id => stem ids of the body alone */
    private array $bodyStems = [];

    /** @var array<int, list<int>> stem id => entry ids holding it in their headline, ascending */
    private array $headlinePostings = [];

    /** @var array<int, list<int>> stem id => entry ids holding it in their body alone, ascending */
    private array $bodyPostings = [];

    /** @var array<int, int> */
    private array $feedOfEntry = [];

    /** @var array<int, list<int>> */
    private array $entriesOfFeed = [];

    /** @var array<int, array<int, true>> feed id => stem ids filler for that feed */
    private array $feedFiller = [];

    /** @var array<int, float> */
    private array $rarities = [];

    /** @var array<int, float> */
    private array $totalWeights = [];

    /** @var array<string, int> language value => entries stemmed in it */
    private array $entriesByLanguage = [];

    /** @var array<string, list<int>> language value and word => its stem id, then its parts' stem ids; none for a seed */
    private array $stemIdsByWord = [];

    private readonly CompoundParts $compoundParts;

    public function __construct(
        private readonly HeadlineFiller $filler,
        private readonly HeadlineEquivalents $equivalents,
    ) {
        $this->compoundParts = new CompoundParts();
    }

    /** Entries must arrive in ascending id order: the postings stay sorted. */
    public function add(WindowEntryModel $entry, string $body): void
    {
        [$headlineIds, $headlineWordIds] = $this->idsOf($entry->title, $entry->language);
        [$allBodyIds, $bodyWordIds] = $this->idsOf($body, $entry->language);
        $bodyIds = array_values(array_diff($allBodyIds, $headlineIds));
        $this->headlineStems[$entry->id] = $headlineIds;
        $this->bodyStems[$entry->id] = $bodyIds;
        $this->compoundParts->addEntry(
            $entry->id,
            [...$headlineIds, ...$bodyIds],
            [...$headlineWordIds, ...$bodyWordIds],
        );
        $this->feedOfEntry[$entry->id] = $entry->feedId;
        $this->entriesOfFeed[$entry->feedId][] = $entry->id;
        $this->post($entry->id, $headlineIds, $bodyIds);
        $this->countLanguageOf($entry);
    }

    public function languageCoverage(): LanguageCoverageModel
    {
        return new LanguageCoverageModel(
            $this->entriesByLanguage,
            $this->entryCount() - array_sum($this->entriesByLanguage),
        );
    }

    public function entryCount(): int
    {
        return \count($this->feedOfEntry);
    }

    public function totalWeight(int $entryId): float
    {
        return $this->totalWeights[$entryId] ??= $this->weighTotal($entryId);
    }

    /** @return array<int, float> earlier entry id => the weight it shares with this one */
    public function sharedWeightsWithEarlier(int $entryId): array
    {
        $shared = [];
        $sharingAHeadline = [];
        foreach ($this->placedStemsOf($entryId) as $stemId => $placeWeight) {
            $holders = $this->compoundParts->holdersLinkedBeyondASharedCompound(
                $this->earlierHoldersOf($stemId, $entryId),
                $stemId,
                $entryId,
            );
            foreach ($holders as $earlierEntryId => $earlierPlaceWeight) {
                $commonPlaceWeight = min($placeWeight, $earlierPlaceWeight);
                $shared[$earlierEntryId] = ($shared[$earlierEntryId] ?? 0.0)
                    + $commonPlaceWeight * $this->rarity($stemId);
                if ($commonPlaceWeight === self::HEADLINE_WEIGHT) {
                    $sharingAHeadline[$earlierEntryId] = true;
                }
            }
        }

        return array_intersect_key($shared, $sharingAHeadline);
    }

    private function countLanguageOf(WindowEntryModel $entry): void
    {
        if (null === $entry->language) {
            return;
        }
        $language = $entry->language->value;
        $this->entriesByLanguage[$language] = ($this->entriesByLanguage[$language] ?? 0) + 1;
    }

    /** @return array{list<int>, list<int>} the ids of every stem, and of the words' own stems */
    private function idsOf(string $text, ?FeedLanguage $language): array
    {
        $ids = [];
        $wordIds = [];
        foreach ($this->equivalents->wordsOf($text) as [$word, $bare]) {
            $stemIds = $this->stemIdsOfWord($word, $bare, $language);
            $wordIds += array_fill_keys(\array_slice($stemIds, 0, 1), true);
            $ids += array_fill_keys($stemIds, true);
        }

        return [array_keys($ids), array_keys($wordIds)];
    }

    /** @return list<int> */
    private function stemIdsOfWord(string $word, string $bare, ?FeedLanguage $language): array
    {
        return $this->stemIdsByWord[$language?->value . ' ' . $word] ??= $this->assignStemIds(
            $this->equivalents->wordStemsOf($word, $bare, $language),
            $language,
        );
    }

    /**
     * @param list<string> $stems the word's own stem, then its parts' stems
     *
     * @return list<int>
     */
    private function assignStemIds(array $stems, ?FeedLanguage $language): array
    {
        if ($this->filler->isSeed($stems[0], $language)) {
            return [];
        }
        $ids = array_map(
            fn (string $stem): int => $this->stemIds[$stem] ??= \count($this->stemIds),
            array_values(array_filter($stems, fn (string $stem): bool => !$this->filler->isSeed($stem, $language))),
        );
        $this->compoundParts->addCompound($ids[0], \array_slice($ids, 1));

        return $ids;
    }

    /**
     * @param list<int> $headlineIds
     * @param list<int> $bodyIds
     */
    private function post(int $entryId, array $headlineIds, array $bodyIds): void
    {
        foreach ($headlineIds as $stemId) {
            $this->headlinePostings[$stemId][] = $entryId;
            $this->documentFrequencies[$stemId] = ($this->documentFrequencies[$stemId] ?? 0) + 1;
        }
        foreach ($bodyIds as $stemId) {
            $this->bodyPostings[$stemId][] = $entryId;
            $this->documentFrequencies[$stemId] = ($this->documentFrequencies[$stemId] ?? 0) + 1;
        }
    }

    private function weighTotal(int $entryId): float
    {
        $total = 0.0;
        foreach ($this->placedStemsOf($entryId) as $stemId => $placeWeight) {
            if (!$this->compoundParts->isOnlyAPartIn($entryId, $stemId)) {
                $total += $placeWeight * $this->rarity($stemId);
            }
        }

        return $total;
    }

    /** @return array<int, float> stem id => place weight, filler left out */
    private function placedStemsOf(int $entryId): array
    {
        $placed = [];
        foreach ($this->bodyStems[$entryId] as $stemId) {
            if (!$this->isFillerFor($entryId, $stemId)) {
                $placed[$stemId] = self::BODY_WEIGHT;
            }
        }
        foreach ($this->headlineStems[$entryId] as $stemId) {
            if (!$this->isFillerFor($entryId, $stemId)) {
                $placed[$stemId] = self::HEADLINE_WEIGHT;
            }
        }

        return $placed;
    }

    /** @return array<int, float> earlier entry id => the stem's place weight there, filler for its feed left out */
    private function earlierHoldersOf(int $stemId, int $entryId): array
    {
        $holders = [];
        foreach ($this->bodyPostings[$stemId] ?? [] as $holderId) {
            if ($holderId >= $entryId) {
                break;
            }
            if (!$this->isFillerFor($holderId, $stemId)) {
                $holders[$holderId] = self::BODY_WEIGHT;
            }
        }
        foreach ($this->headlinePostings[$stemId] ?? [] as $holderId) {
            if ($holderId >= $entryId) {
                break;
            }
            if (!$this->isFillerFor($holderId, $stemId)) {
                $holders[$holderId] = self::HEADLINE_WEIGHT;
            }
        }

        return $holders;
    }

    private function isFillerFor(int $entryId, int $stemId): bool
    {
        return $this->filler->isWindowFiller($this->documentFrequencies[$stemId], $this->entryCount())
            || isset($this->feedFillerOf($this->feedOfEntry[$entryId])[$stemId]);
    }

    /** @return array<int, true> */
    private function feedFillerOf(int $feedId): array
    {
        return $this->feedFiller[$feedId] ??= $this->countFeedFiller($feedId);
    }

    /** @return array<int, true> */
    private function countFeedFiller(int $feedId): array
    {
        $entryIds = $this->entriesOfFeed[$feedId];
        $frequencies = [];
        foreach ($entryIds as $entryId) {
            foreach ([...$this->headlineStems[$entryId], ...$this->bodyStems[$entryId]] as $stemId) {
                $frequencies[$stemId] = ($frequencies[$stemId] ?? 0) + 1;
            }
        }
        $filler = array_filter(
            $frequencies,
            fn (int $frequency): bool => $this->filler->isFeedFiller($frequency, \count($entryIds)),
        );

        return array_fill_keys(array_keys($filler), true);
    }

    private function rarity(int $stemId): float
    {
        return $this->rarities[$stemId] ??= $this->filler->rarityWeight(
            $this->documentFrequencies[$stemId],
            $this->entryCount(),
        );
    }
}
