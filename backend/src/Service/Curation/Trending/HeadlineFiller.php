<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\StopWordStems;

final readonly class HeadlineFiller
{
    private const float WINDOW_SHARE = 0.03;
    private const int COUNTED_ENTRIES_FLOOR = 1_500;
    private const int FEED_MINIMUM_ENTRIES = 4;
    private const float FEED_SHARE = 0.5;

    public function __construct(private StopWordStems $stopWordStems)
    {
    }

    public function isSeed(string $stem, ?FeedLanguage $language): bool
    {
        return $this->stopWordStems->isStopWordStem($stem, $language);
    }

    public function isWindowFiller(int $documentFrequency, int $entryCount): bool
    {
        return $documentFrequency >= self::WINDOW_SHARE * self::countedEntries($entryCount);
    }

    public function isFeedFiller(int $feedDocumentFrequency, int $feedEntryCount): bool
    {
        return $feedEntryCount >= self::FEED_MINIMUM_ENTRIES
            && $feedDocumentFrequency >= self::FEED_SHARE * $feedEntryCount;
    }

    public function rarityWeight(int $documentFrequency, int $entryCount): float
    {
        return log((self::countedEntries($entryCount) + 2) / ($documentFrequency + 1));
    }

    private static function countedEntries(int $entryCount): int
    {
        return max($entryCount, self::COUNTED_ENTRIES_FLOOR);
    }
}
