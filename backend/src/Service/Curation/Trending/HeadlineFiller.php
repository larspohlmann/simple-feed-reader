<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Stemming\WordStemmer;
use App\Service\Foundation\Text\StopWords\StopWordsInterface;

final readonly class HeadlineFiller
{
    private const float WINDOW_SHARE = 0.03;
    private const int COUNTED_ENTRIES_FLOOR = 1_500;
    private const int FEED_MINIMUM_ENTRIES = 4;
    private const float FEED_SHARE = 0.5;

    /** @var array<string, array<string, true>> language => its seed stems */
    private array $seedStemsByLanguage;

    /** @var array<string, true> the seeds of a feed without a language, in WordStems' cut form */
    private array $cutSeedStems;

    public function __construct(WordStemmer $stemmer, StopWordsInterface $stopWords)
    {
        $this->seedStemsByLanguage = self::seedStemsByLanguage($stemmer, $stopWords);
        $this->cutSeedStems = self::cutSeedStems($stopWords);
    }

    public function isSeed(string $stem, ?FeedLanguage $language): bool
    {
        $seedStems = $language === null ? $this->cutSeedStems : $this->seedStemsByLanguage[$language->value];

        return isset($seedStems[$stem]);
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

    /** @return array<string, array<string, true>> */
    private static function seedStemsByLanguage(WordStemmer $stemmer, StopWordsInterface $stopWords): array
    {
        $seedStems = [];
        foreach (FeedLanguage::cases() as $language) {
            $stems = array_map(
                static fn (string $word): string => $stemmer->stem($word, $language),
                $stopWords->listWords($language),
            );
            $seedStems[$language->value] = array_fill_keys($stems, true);
        }

        return $seedStems;
    }

    /** @return array<string, true> */
    private static function cutSeedStems(StopWordsInterface $stopWords): array
    {
        $cutStems = [];
        foreach (FeedLanguage::cases() as $language) {
            foreach ($stopWords->listWords($language) as $word) {
                if (WordStems::isKept(WordStems::bare($word))) {
                    $cutStems[WordStems::cut($word)] = true;
                }
            }
        }

        return $cutStems;
    }

    private static function countedEntries(int $entryCount): int
    {
        return max($entryCount, self::COUNTED_ENTRIES_FLOOR);
    }
}
