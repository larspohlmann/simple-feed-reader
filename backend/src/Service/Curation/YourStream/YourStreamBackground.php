<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\Model\RarityWindowModel;
use App\Service\Curation\YourStream\Pass\RaritySweepPass;
use App\Service\Curation\YourStream\Pass\RarityTally;
use App\Service\Foundation\Clock\NaiveUtcClock;

/** Counts the wanted stems among an account's newest Your stream entries of the last thirty days; the sample cap bounds the walk. */
final readonly class YourStreamBackground
{
    public const int WINDOW_DAYS = 30;
    public const int SAMPLE_ENTRIES = 5000;
    public const int CHUNK = 1000;

    public function __construct(
        private YourStreamSourceRepository $sources,
        private EntryStemmingFactory $stemmingFactory,
        private NaiveUtcClock $clock,
    ) {
    }

    public function buildSweepPass(): RaritySweepPass
    {
        $since = $this->clock->now()->modify(\sprintf('-%d days', self::WINDOW_DAYS));

        return new RaritySweepPass($this->stemmingFactory->create(), $this->readWindowSince($since));
    }

    public function readWindowSince(\DateTimeImmutable $since): RarityWindowModel
    {
        return new RarityWindowModel($since, $this->sources->findIdBeforeFirstEntrySince($since) ?? 0);
    }

    /** @param array<string, array<array-key, true>> $wantedStemsByLanguageKey */
    public function collectBackground(int $userId, RaritySweepPass $pass, array $wantedStemsByLanguageKey): RarityTally
    {
        $tally = new RarityTally($wantedStemsByLanguageKey);
        $beforeId = PHP_INT_MAX;
        $remaining = self::SAMPLE_ENTRIES;
        do {
            $limit = min(self::CHUNK, $remaining);
            $entries = $this->sources->findNewestEntries($userId, $pass->window, $beforeId, $limit);
            foreach ($entries as $entry) {
                $tally->count($pass->stemming->collectStems($entry));
                $beforeId = $entry->id;
            }
            $remaining -= \count($entries);
        } while ($remaining > 0 && \count($entries) === $limit);

        return $tally;
    }
}
