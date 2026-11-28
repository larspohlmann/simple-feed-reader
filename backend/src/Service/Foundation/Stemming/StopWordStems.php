<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Text\StopWords\StopWordsInterface;

final readonly class StopWordStems
{
    /** @var array<string, array<string, true>> language => its stop-word stems */
    private array $stemsByLanguage;

    /** @var array<string, true> the stop-word stems of text without a language, in WordStems' cut form */
    private array $cutStems;

    public function __construct(WordStemmer $stemmer, StopWordsInterface $stopWords)
    {
        $this->stemsByLanguage = self::buildStemsByLanguage($stemmer, $stopWords);
        $this->cutStems = self::buildCutStems($stopWords);
    }

    public function isStopWordStem(string $stem, ?FeedLanguage $language): bool
    {
        $stems = $language === null ? $this->cutStems : $this->stemsByLanguage[$language->value];

        return isset($stems[$stem]);
    }

    /** @return array<string, array<string, true>> */
    private static function buildStemsByLanguage(WordStemmer $stemmer, StopWordsInterface $stopWords): array
    {
        $stemsByLanguage = [];
        foreach (FeedLanguage::cases() as $language) {
            $stems = array_map(
                static fn (string $word): string => $stemmer->stem($word, $language),
                $stopWords->listWords($language),
            );
            $stemsByLanguage[$language->value] = array_fill_keys($stems, true);
        }

        return $stemsByLanguage;
    }

    /** @return array<string, true> */
    private static function buildCutStems(StopWordsInterface $stopWords): array
    {
        $cutStems = [];
        foreach (FeedLanguage::cases() as $language) {
            foreach ($stopWords->listWords($language) as $word) {
                if (WordStems::isKept(WordStems::bare($word))) {
                    $cutStems[] = WordStems::cut($word);
                }
            }
        }

        return array_fill_keys($cutStems, true);
    }
}
