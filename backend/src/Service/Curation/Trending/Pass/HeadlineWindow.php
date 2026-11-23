<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineEquivalents;
use App\Service\Curation\Trending\HeadlineFiller;
use App\Service\Curation\Trending\Model\EntryStemIdsModel;
use App\Service\Curation\Trending\Model\LanguageCoverageModel;
use App\Service\Curation\Trending\Model\SharedArticleModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Support\PartWeightCaps;

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

    /** @var array<string, ?int> language value and word => its stem id; null for a seed */
    private array $stemIdByWord = [];

    /** @var array<string, list<int>> language value and word => its parts' stem ids, for a compound */
    private array $partStemIdsByWord = [];

    private readonly CompoundParts $compoundParts;

    public function __construct(
        private readonly HeadlineFiller $filler,
        private readonly HeadlineEquivalents $equivalents,
    ) {
        $this->compoundParts = new CompoundParts();
    }

    /** Entries must arrive in ascending id order: the postings stay sorted. */
    public function add(WindowEntryModel $entry, string $body, ?SharedArticleModel $article): void
    {
        $stems = EntryStemIdsModel::fromHeadlinesAndBodies(
            [
                $this->indexStemIds($entry->title, $entry->language),
                $this->indexStemIds($article->title ?? '', $article?->language),
            ],
            [
                $this->indexStemIds($body, $entry->language),
                $this->indexStemIds($article->body ?? '', $article?->language),
            ],
        );
        $this->headlineStems[$entry->id] = $stems->headlineIds;
        $this->bodyStems[$entry->id] = $stems->bodyIds;
        $this->compoundParts->addEntry($entry->id, $stems->partOnlyIds);
        $this->compoundParts->holdCompoundsAmong($entry->id, $stems->headlineIds, self::HEADLINE_WEIGHT);
        $this->compoundParts->holdCompoundsAmong($entry->id, $stems->bodyIds, self::BODY_WEIGHT);
        $this->feedOfEntry[$entry->id] = $entry->feedId;
        $this->entriesOfFeed[$entry->feedId][] = $entry->id;
        $this->post($entry->id, $stems->headlineIds, $stems->bodyIds);
        $this->tallyLanguage($entry);
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

    /** @return list<int> */
    public function collectHeadlineStemIds(int $entryId): array
    {
        return array_values(array_filter(
            $this->headlineStems[$entryId],
            fn (int $stemId): bool => !$this->isFillerFor($entryId, $stemId),
        ));
    }

    public function computeRarity(int $stemId): float
    {
        return $this->rarities[$stemId] ??= $this->filler->rarityWeight(
            $this->documentFrequencies[$stemId],
            $this->entryCount(),
        );
    }

    /** @return array<int, float> earlier entry id => the weight it shares with this one */
    public function sharedWeightsWithEarlier(int $entryId): array
    {
        $shared = [];
        $sharingAHeadline = [];
        $parts = [];
        foreach ($this->collectPlacedStems($entryId) as $stemId => $placeWeight) {
            $holders = $this->collectEarlierHolders($stemId, $entryId);
            $partLinks = $this->compoundParts->buildPartLinks($stemId, $entryId, $holders);
            $rarity = $this->computeRarity($stemId);
            $wordHolders = $partLinks === [] ? $holders : array_diff_key($holders, $partLinks);
            foreach ($wordHolders as $earlierEntryId => $earlierPlaceWeight) {
                $commonPlaceWeight = min($placeWeight, $earlierPlaceWeight);
                $shared[$earlierEntryId] = ($shared[$earlierEntryId] ?? 0.0) + $commonPlaceWeight * $rarity;
                if ($commonPlaceWeight === self::HEADLINE_WEIGHT) {
                    $sharingAHeadline[$earlierEntryId] = true;
                }
            }
            foreach (array_filter($partLinks) as $earlierEntryId => $compoundsThrough) {
                $commonPlaceWeight = min($placeWeight, $holders[$earlierEntryId]);
                $shared[$earlierEntryId] ??= 0.0;
                $parts[$earlierEntryId][] = [
                    $commonPlaceWeight * $rarity,
                    $this->compoundsCapping($entryId, $earlierEntryId, $compoundsThrough),
                ];
                if ($commonPlaceWeight === self::HEADLINE_WEIGHT) {
                    $sharingAHeadline[$earlierEntryId] = true;
                }
            }
        }

        $weights = PartWeightCaps::addedTo($shared, $parts, $this->computeRarity(...));

        return array_intersect_key($weights, $sharingAHeadline);
    }

    /**
     * A share is measured in the lighter entry's own accounting, where a part it holds as a word weighs whole. A part
     * both entries hold only through compounds, or a pair of equal weight, is held to both entries' compounds.
     *
     * @param array{array<int, float>, array<int, float>} $compoundsThrough in this entry, then in the earlier one
     *
     * @return array<int, float>
     */
    private function compoundsCapping(int $entryId, int $earlierEntryId, array $compoundsThrough): array
    {
        [$throughThis, $throughEarlier] = $compoundsThrough;
        if ($throughThis !== [] && $throughEarlier !== []) {
            return $throughThis + $throughEarlier;
        }

        return match ($this->totalWeight($entryId) <=> $this->totalWeight($earlierEntryId)) {
            -1 => $throughThis,
            1 => $throughEarlier,
            default => $throughThis + $throughEarlier,
        };
    }

    private function tallyLanguage(WindowEntryModel $entry): void
    {
        if (null === $entry->language) {
            return;
        }
        $language = $entry->language->value;
        $this->entriesByLanguage[$language] = ($this->entriesByLanguage[$language] ?? 0) + 1;
    }

    /** @return array<int, bool> stem id => whether a word of the text holds it, rather than only a compound's part */
    private function indexStemIds(string $text, ?FeedLanguage $language): array
    {
        $ids = [];
        foreach ($this->equivalents->canonicalizeWords($text) as [$word, $bare]) {
            $key = $language?->value . ' ' . $word;
            if (!\array_key_exists($key, $this->stemIdByWord)) {
                $this->assignStemIds($key, $this->equivalents->stemWordAndParts($word, $bare, $language), $language);
            }
            $stemId = $this->stemIdByWord[$key];
            if (null !== $stemId) {
                $ids[$stemId] = true;
                $ids += array_fill_keys($this->partStemIdsByWord[$key] ?? [], false);
            }
        }

        return $ids;
    }

    /** @param list<string> $stems the word's own stem, then its parts' stems */
    private function assignStemIds(string $key, array $stems, ?FeedLanguage $language): void
    {
        if ($this->filler->isSeed($stems[0], $language)) {
            $this->stemIdByWord[$key] = null;

            return;
        }
        $ids = array_map(
            fn (string $stem): int => $this->stemIds[$stem] ??= \count($this->stemIds),
            array_values(array_filter($stems, fn (string $stem): bool => !$this->filler->isSeed($stem, $language))),
        );
        $partIds = \array_slice($ids, 1);
        $this->stemIdByWord[$key] = $ids[0];
        if ($partIds !== []) {
            $this->partStemIdsByWord[$key] = $partIds;
            $this->addCompound($ids[0], $partIds);
        }
    }

    /** @param non-empty-list<int> $partStemIds */
    private function addCompound(int $compoundStemId, array $partStemIds): void
    {
        if (!$this->compoundParts->isCompound($compoundStemId)) {
            $headlineHolders = $this->headlinePostings[$compoundStemId] ?? [];
            $bodyHolders = $this->bodyPostings[$compoundStemId] ?? [];
            $this->compoundParts->holdCompoundIn($compoundStemId, $headlineHolders, self::HEADLINE_WEIGHT);
            $this->compoundParts->holdCompoundIn($compoundStemId, $bodyHolders, self::BODY_WEIGHT);
        }
        $this->compoundParts->addCompound($compoundStemId, $partStemIds);
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
        foreach ($this->collectPlacedStems($entryId) as $stemId => $placeWeight) {
            if (!$this->compoundParts->isOnlyAPartIn($entryId, $stemId)) {
                $total += $placeWeight * $this->computeRarity($stemId);
            }
        }

        return $total;
    }

    /** @return array<int, float> stem id => place weight, filler left out */
    private function collectPlacedStems(int $entryId): array
    {
        $placed = [];
        $places = [
            [$this->bodyStems[$entryId], self::BODY_WEIGHT],
            [$this->headlineStems[$entryId], self::HEADLINE_WEIGHT],
        ];
        foreach ($places as [$stemIds, $placeWeight]) {
            foreach ($stemIds as $stemId) {
                if (!$this->isFillerFor($entryId, $stemId)) {
                    $placed[$stemId] = $placeWeight;
                }
            }
        }

        return $placed;
    }

    /** @return array<int, float> earlier entry id => the stem's place weight there, filler for its feed left out */
    private function collectEarlierHolders(int $stemId, int $entryId): array
    {
        $holders = [];
        $places = [
            [$this->bodyPostings[$stemId] ?? [], self::BODY_WEIGHT],
            [$this->headlinePostings[$stemId] ?? [], self::HEADLINE_WEIGHT],
        ];
        foreach ($places as [$holderIds, $placeWeight]) {
            foreach ($holderIds as $holderId) {
                if ($holderId >= $entryId) {
                    break;
                }
                if (!$this->isFeedFillerFor($holderId, $stemId)) {
                    $holders[$holderId] = $placeWeight;
                }
            }
        }

        return $holders;
    }

    private function isFillerFor(int $entryId, int $stemId): bool
    {
        return $this->filler->isWindowFiller($this->documentFrequencies[$stemId], $this->entryCount())
            || $this->isFeedFillerFor($entryId, $stemId);
    }

    private function isFeedFillerFor(int $entryId, int $stemId): bool
    {
        return isset($this->countFeedFillerOnce($this->feedOfEntry[$entryId])[$stemId]);
    }

    /** @return array<int, true> */
    private function countFeedFillerOnce(int $feedId): array
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
}
