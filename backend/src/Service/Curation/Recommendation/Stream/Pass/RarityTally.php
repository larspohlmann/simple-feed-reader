<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Pass;

use App\Service\Curation\Recommendation\Stream\Model\EntryStemsModel;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermsModel;

/** The entries and the document frequencies of the wanted stems, counted by language key over one pass. */
final class RarityTally
{
    /** @var array<string, int> */
    private array $entryCounts = [];

    /** @var array<string, array<array-key, int>> */
    private array $documentFrequencies = [];

    /** @param array<string, array<array-key, true>> $wantedStemsByLanguageKey */
    public function __construct(private readonly array $wantedStemsByLanguageKey)
    {
    }

    public function count(EntryStemsModel $entry): void
    {
        $key = InterestTermsModel::languageKey($entry->language);
        $this->entryCounts[$key] = ($this->entryCounts[$key] ?? 0) + 1;
        foreach (array_keys($entry->positionWeights) as $stemKey) {
            $stem = (string) $stemKey;
            if (isset($this->wantedStemsByLanguageKey[$key][$stem])) {
                $this->documentFrequencies[$key][$stem] = ($this->documentFrequencies[$key][$stem] ?? 0) + 1;
            }
        }
    }

    public function countEntries(string $languageKey): int
    {
        return $this->entryCounts[$languageKey] ?? 0;
    }

    public function countDocuments(string $languageKey, string $stem): int
    {
        return $this->documentFrequencies[$languageKey][$stem] ?? 0;
    }
}
