<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Pass;

use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\Model\EntryStemsModel;
use App\Service\Curation\YourStream\Model\StreamStrengthsModel;
use App\Service\Curation\YourStream\YourStreamWordSelection;

/** One account's interests, counted by language key and stem; a one-word saved search counts as several articles. */
final class InterestTally
{
    /** @var array<string, float> */
    private array $articleCounts = [];

    /** @var array<string, array<array-key, float>> */
    private array $supports = [];

    /** @var array<string, array<array-key, int>> */
    private array $articleCountsWith = [];

    /** @var array<string, array<array-key, array<array-key, int>>> surface => how often, in the order first seen */
    private array $surfaceCounts = [];

    /** @var array<string, array<array-key, string>> */
    private array $searchTerms = [];

    public function recordArticle(EntryStemsModel $stems): void
    {
        $key = StreamStrengthsModel::languageKey($stems->language);
        $this->articleCounts[$key] = ($this->articleCounts[$key] ?? 0.0) + 1;
        foreach ($stems->positionWeights as $stem => $weight) {
            if (mb_strlen((string) $stem) > YourStreamWord::STEM_MAX_LENGTH) {
                continue;
            }
            $this->recordStem($key, (string) $stem, $weight, 1);
            foreach ($stems->stemWords[$stem] ?? [] as $surface) {
                $this->surfaceCounts[$key][$stem][$surface] = ($this->surfaceCounts[$key][$stem][$surface] ?? 0) + 1;
            }
        }
    }

    /** @param array<array-key, float> $stemShares a one-word search, counted as several articles */
    public function recordSearchTerm(?FeedLanguage $language, array $stemShares, string $term): void
    {
        $this->recordSearch($language, $stemShares, $term, YourStreamWordSelection::SEARCH_ARTICLES);
    }

    /** @param array<array-key, float> $stemShares a search of several words, each counted as one article */
    public function recordSearchPhrase(?FeedLanguage $language, array $stemShares, string $term): void
    {
        $this->recordSearch($language, $stemShares, $term, 1);
    }

    /** @return array<string, array<array-key, true>> */
    public function collectWantedStems(): array
    {
        return array_map(
            static fn (array $supports): array => array_fill_keys(array_keys($supports), true),
            $this->supports,
        );
    }

    /** @return list<string> in FeedLanguage::cases() order, the cut form last */
    public function collectLanguageKeys(): array
    {
        return array_values(array_filter(
            [...FeedLanguage::values(), StreamStrengthsModel::languageKey(null)],
            fn (string $key): bool => isset($this->articleCounts[$key]),
        ));
    }

    public function countArticles(string $languageKey): float
    {
        return $this->articleCounts[$languageKey] ?? 0.0;
    }

    public function countArticlesWith(string $languageKey, string $stem): int
    {
        return $this->articleCountsWith[$languageKey][$stem] ?? 0;
    }

    public function readSupport(string $languageKey, string $stem): float
    {
        return $this->supports[$languageKey][$stem] ?? 0.0;
    }

    /** The first search's term as typed, else the surface seen most often, else the stem. */
    public function readDisplayWord(string $languageKey, string $stem): string
    {
        return $this->searchTerms[$languageKey][$stem]
            ?? self::pickSurface($this->surfaceCounts[$languageKey][$stem] ?? [])
            ?? $stem;
    }

    /** @param array<array-key, int> $surfaceCounts on a tie the shorter, then the first seen */
    private static function pickSurface(array $surfaceCounts): ?string
    {
        $picked = null;
        $pickedCount = 0;
        foreach ($surfaceCounts as $surfaceKey => $count) {
            $surface = (string) $surfaceKey;
            $isShorter = $picked !== null && mb_strlen($surface) < mb_strlen($picked);
            if ($count > $pickedCount || ($count === $pickedCount && $isShorter)) {
                $picked = $surface;
                $pickedCount = $count;
            }
        }

        return $picked;
    }

    /** @param array<array-key, float> $stemShares */
    private function recordSearch(?FeedLanguage $language, array $stemShares, string $term, int $articles): void
    {
        if ($stemShares === []) {
            return;
        }
        $key = StreamStrengthsModel::languageKey($language);
        $this->articleCounts[$key] = ($this->articleCounts[$key] ?? 0.0) + $articles;
        foreach ($stemShares as $stem => $share) {
            $this->recordStem($key, (string) $stem, $articles * $share, $articles);
            $this->searchTerms[$key][$stem] ??= $term;
        }
    }

    private function recordStem(string $languageKey, string $stem, float $support, int $articles): void
    {
        $this->supports[$languageKey][$stem] = ($this->supports[$languageKey][$stem] ?? 0.0) + $support;
        $counted = $this->articleCountsWith[$languageKey][$stem] ?? 0;
        $this->articleCountsWith[$languageKey][$stem] = $counted + $articles;
    }
}
