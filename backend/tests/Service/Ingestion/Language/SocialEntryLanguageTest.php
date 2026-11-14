<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\SocialEntryLanguage;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\TestCase;

final class SocialEntryLanguageTest extends TestCase
{
    private const string SHORT_ENGLISH = 'Airports reopen tonight after the storm';
    private const string LONG_GERMAN = 'Der Bundeskanzler sagte am Montag in Berlin, dass die Regierung weitere '
        . 'Hilfen für die Menschen in den vom Hochwasser betroffenen Regionen vorbereitet und dafür zusätzliche '
        . 'Mittel bereitstellt.';

    public function testAShortTextKeepsTheLanguageItsAuthorStated(): void
    {
        self::assertSame(FeedLanguage::German, self::languageOf(self::SHORT_ENGLISH, 'de'));
    }

    public function testALongTextTheDetectorReadsAsAnotherLanguageOverridesTheStatedOne(): void
    {
        self::assertGreaterThanOrEqual(150, mb_strlen(self::LONG_GERMAN));
        self::assertSame(FeedLanguage::German, self::languageOf(self::LONG_GERMAN, 'en'));
    }

    public function testALongTextTheDetectorAgreesWithKeepsTheStatedLanguage(): void
    {
        self::assertSame(FeedLanguage::German, self::languageOf(self::LONG_GERMAN, 'de'));
    }

    public function testALanguageOutsideTheFourIsStoredAsNone(): void
    {
        self::assertNull(self::languageOf(self::SHORT_ENGLISH, 'pt'));
        self::assertNull(self::languageOf(self::LONG_GERMAN, 'x-lemmy-130'));
    }

    public function testAPostStatingNothingGetsTheDetectorsGuess(): void
    {
        self::assertSame(FeedLanguage::English, self::languageOf(self::SHORT_ENGLISH, null));
    }

    public function testARegionTagIsReadAsItsLanguage(): void
    {
        self::assertSame(FeedLanguage::English, self::languageOf('Hallo', 'en-US'));
    }

    public function testTheLengthIsTheBodysNotTheHeadlines(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            null,
            Headline::derived(self::LONG_GERMAN),
            null,
            null,
            '<p>Hallo</p>',
            null,
            statedLanguage: 'en',
        );

        self::assertSame(FeedLanguage::English, self::language()->of($entry));
    }

    private static function languageOf(string $body, ?string $statedLanguage): ?FeedLanguage
    {
        $entry = new ParsedEntryModel(
            'guid',
            null,
            Headline::derived('…'),
            null,
            null,
            '<p>' . $body . '</p>',
            null,
            statedLanguage: $statedLanguage,
        );

        return self::language()->of($entry);
    }

    private static function language(): SocialEntryLanguage
    {
        return new SocialEntryLanguage(new LanguageGuess(new LanguageDetectionFactory()->create()));
    }
}
