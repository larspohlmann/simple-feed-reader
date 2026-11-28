<?php

declare(strict_types=1);

namespace App\Tests\Service\Foundation\Text\StopWords;

use App\Enum\FeedLanguage;
use App\Service\Foundation\Text\StopWords\BundledStopWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BundledStopWordsDataTest extends TestCase
{
    /** @return iterable<string, array{FeedLanguage}> */
    public static function languages(): iterable
    {
        foreach (FeedLanguage::cases() as $language) {
            yield $language->value => [$language];
        }
    }

    #[DataProvider('languages')]
    public function testEveryLanguageHasWords(FeedLanguage $language): void
    {
        $this->assertNotEmpty(self::bundled()->listWords($language));
    }

    #[DataProvider('languages')]
    public function testEveryWordIsCasefoldedLettersAndSorted(FeedLanguage $language): void
    {
        $words = self::bundled()->listWords($language);
        $sorted = array_values(array_unique($words));
        sort($sorted, \SORT_STRING);

        foreach ($words as $word) {
            $this->assertMatchesRegularExpression('/^\p{L}+$/u', $word);
            $this->assertSame(mb_convert_case($word, \MB_CASE_FOLD, 'UTF-8'), $word);
        }
        $this->assertSame($sorted, $words);
    }

    public function testTheListsHoldTheirLanguagesFunctionWords(): void
    {
        $this->assertContains('und', self::bundled()->listWords(FeedLanguage::German));
        $this->assertContains('the', self::bundled()->listWords(FeedLanguage::English));
        $this->assertContains('les', self::bundled()->listWords(FeedLanguage::French));
        $this->assertContains('los', self::bundled()->listWords(FeedLanguage::Spanish));
    }

    public function testTheFrenchAndSpanishListsHoldTheirClosedClassWords(): void
    {
        foreach (['après', 'contre', 'depuis', 'très'] as $word) {
            $this->assertContains($word, self::bundled()->listWords(FeedLanguage::French));
        }
        foreach (['tras', 'según'] as $word) {
            $this->assertContains($word, self::bundled()->listWords(FeedLanguage::Spanish));
        }
    }

    public function testTheGermanListHoldsTheBoilerplateFeedsAppend(): void
    {
        foreach (['erschien', 'weiterlesen'] as $word) {
            $this->assertContains($word, self::bundled()->listWords(FeedLanguage::German));
        }
    }

    private static function bundled(): BundledStopWords
    {
        return new BundledStopWords(dirname(__DIR__, 5) . '/resources/stopwords/stopwords.php');
    }
}
