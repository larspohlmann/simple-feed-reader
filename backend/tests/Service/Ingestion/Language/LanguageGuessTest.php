<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageGuessTest extends TestCase
{
    /** @return iterable<string, array{string, FeedLanguage}> */
    public static function shortTexts(): iterable
    {
        yield 'German' => ['Regierung beschließt neues Gesetz', FeedLanguage::German];
        yield 'French' => ['Le gouvernement adopte une nouvelle loi', FeedLanguage::French];
        yield 'Spanish' => ['El gobierno aprueba una nueva ley', FeedLanguage::Spanish];
        yield 'English' => ['Airports reopen tonight after the storm', FeedLanguage::English];
    }

    #[DataProvider('shortTexts')]
    public function testAShortTextGetsTheDetectorsTopLanguage(string $text, FeedLanguage $language): void
    {
        self::assertSame($language, self::guess()->bestFor($text));
    }

    public function testATextWithoutLettersGetsNoGuess(): void
    {
        self::assertNull(self::guess()->bestFor('2031 – 42 % … 12:30!'));
    }

    public function testAnEmptyTextGetsNoGuess(): void
    {
        self::assertNull(self::guess()->bestFor(''));
    }

    private static function guess(): LanguageGuess
    {
        return new LanguageGuess(new LanguageDetectionFactory()->create());
    }
}
