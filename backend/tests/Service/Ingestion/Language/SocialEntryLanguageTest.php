<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\SocialEntryLanguage;
use App\Service\Ingestion\Language\StopWordVote;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Tests\Support\LanguageWiring;
use App\Tests\Support\StubbedDetector;
use LanguageDetection\Language;
use PHPUnit\Framework\TestCase;

final class SocialEntryLanguageTest extends TestCase
{
    public function testAShortTextKeepsTheLanguageItsAuthorStated(): void
    {
        self::assertSame(FeedLanguage::German, self::languageOf(self::textOfLength(5), 'de', 'en'));
    }

    public function testATextJustUnderTheLimitKeepsTheStatedLanguage(): void
    {
        self::assertSame(
            FeedLanguage::English,
            self::languageOf(self::textOfLength(self::limit() - 1), 'en', 'de'),
        );
    }

    public function testTheLimitCountsCharactersNotBytes(): void
    {
        $body = str_repeat('ä', self::limit() - 1);

        self::assertSame(FeedLanguage::English, self::languageOf($body, 'en', 'de'));
    }

    public function testATextAtTheLimitOverridesTheStatedLanguage(): void
    {
        self::assertSame(
            FeedLanguage::German,
            self::languageOf(self::textOfLength(self::limit()), 'en', 'de'),
        );
    }

    public function testALongTextWithoutLettersKeepsTheStatedLanguage(): void
    {
        $digits = substr(str_repeat('2031 – 42 % … 12:30! ', self::limit()), 0, self::limit());

        self::assertSame(FeedLanguage::English, self::languageOf($digits, 'en', 'de'));
    }

    public function testALongTextTheDetectorAgreesWithKeepsTheStatedLanguage(): void
    {
        self::assertSame(
            FeedLanguage::German,
            self::languageOf(self::textOfLength(self::limit()), 'de', 'de'),
        );
    }

    public function testALongTextTheDetectorPlacesOutsideTheFourKeepsTheStatedLanguage(): void
    {
        self::assertSame(
            FeedLanguage::English,
            self::languageOf(self::textOfLength(self::limit()), 'en', 'it'),
        );
    }

    public function testALanguageOutsideTheFourIsStoredAsNone(): void
    {
        self::assertNull(self::languageOf(self::textOfLength(1), 'pt', 'en'));
    }

    public function testALongTextStatedOutsideTheFourIsStoredAsNoneWhateverTheDetectorSays(): void
    {
        self::assertNull(self::languageOf(self::textOfLength(self::limit()), 'x-lemmy-130', 'de'));
    }

    public function testAPostStatingNothingGetsTheDetectorsGuess(): void
    {
        self::assertSame(FeedLanguage::French, self::languageOf(self::textOfLength(5), null, 'fr'));
    }

    public function testAPostStatingNothingAndGuessedOutsideTheFourGetsNone(): void
    {
        self::assertNull(self::languageOf(self::textOfLength(5), null, 'it'));
    }

    public function testAShortPostStatingNothingAndGuessedOutsideTheFourGetsTheStopwordVote(): void
    {
        self::assertSame(FeedLanguage::German, self::languageOf('Aus dem Archiv!', null, 'la'));
    }

    public function testTheDetectorsGuessInTheFourWinsOverTheVote(): void
    {
        self::assertSame(FeedLanguage::French, self::languageOf('Aus dem Archiv!', null, 'fr'));
    }

    public function testALongPostGuessedOutsideTheFourGetsNoVote(): void
    {
        self::assertNull(self::languageOf(str_pad('Aus dem Archiv!', self::limit(), ' der'), null, 'it'));
    }

    public function testAShortPostStatedOutsideTheFourGetsNoVote(): void
    {
        self::assertNull(self::languageOf('Aus dem Archiv!', 'it', 'it'));
    }

    public function testTheVoteReadsAFeedsTitleToo(): void
    {
        $language = self::reading(StubbedDetector::saying('la'));

        self::assertSame(FeedLanguage::German, $language->resolveLanguage(self::titled('Aus dem Archiv', 'xx')));
    }

    public function testTheVoteCountsAHeadlineDerivedFromTheBodyOnce(): void
    {
        $body = 'Ottimo articolo, da leggere. La legge di bilancio';
        $language = self::reading(StubbedDetector::saying('it'));

        self::assertNull($language->resolveLanguage(self::entry('Ottimo articolo, da leggere.', $body, null)));
    }

    public function testARealShortEnglishPostTheDetectorReadsAsInterlinguaIsEnglish(): void
    {
        $entry = self::entry('…', 'Palantir exec nominated to lead CISA', null);

        self::assertSame(FeedLanguage::English, LanguageWiring::socialEntryLanguage()->resolveLanguage($entry));
    }

    public function testAnEnglishLookingMentionDoesNotGiveARussianPostALanguage(): void
    {
        $body = 'Новости дня @in_the_news';

        self::assertNull(LanguageWiring::socialEntryLanguage()->resolveLanguage(self::entry($body, $body, null)));
    }

    public function testARegionTagIsReadAsItsLanguage(): void
    {
        self::assertSame(FeedLanguage::English, self::languageOf(self::textOfLength(5), 'en-US', 'de'));
    }

    public function testTheHeadlineAndTheBodyAreBothGuessedFrom(): void
    {
        $detector = StubbedDetector::saying('de');

        self::reading($detector)->resolveLanguage(self::entry('A headline', 'A body', null));

        self::assertSame(["A headline\nA body"], $detector->seenTexts());
    }

    public function testASummaryIsReadInPlaceOfTheContent(): void
    {
        $detector = StubbedDetector::saying('de');

        self::reading($detector)
            ->resolveLanguage(self::entry('…', 'The summary', null, '<p>The content</p>'));

        self::assertSame(["…\nThe summary"], $detector->seenTexts());
    }

    public function testAShortPostStatingItsLanguageIsNotGuessed(): void
    {
        $detector = StubbedDetector::saying('de');

        self::reading($detector)->resolveLanguage(self::entry('A headline', 'A body', 'en'));

        self::assertSame([], $detector->seenTexts());
    }

    public function testAPostStatedOutsideTheFourIsNotGuessed(): void
    {
        $detector = StubbedDetector::saying('de');

        self::reading($detector)
            ->resolveLanguage(self::entry('A headline', self::textOfLength(self::limit()), 'it'));

        self::assertSame([], $detector->seenTexts());
    }

    public function testTheLengthIsTheBodysNotTheHeadlines(): void
    {
        $entry = self::entry(self::textOfLength(self::limit()), 'short', 'en');
        $language = self::reading(StubbedDetector::saying('de'));

        self::assertSame(FeedLanguage::English, $language->resolveLanguage($entry));
    }

    public function testARealDetectorOverridesAStatedLanguageOnALongUnambiguousText(): void
    {
        $german = 'Der Bundeskanzler sagte am Montag in Berlin, dass die Regierung weitere Hilfen für die Menschen '
            . 'in den vom Hochwasser betroffenen Regionen vorbereitet und dafür zusätzliche Mittel bereitstellt.';
        $language = self::reading(new LanguageDetectionFactory()->create());

        self::assertGreaterThanOrEqual(self::limit(), mb_strlen($german));
        self::assertSame(FeedLanguage::German, $language->resolveLanguage(self::entry('…', $german, 'en')));
    }

    private static function limit(): int
    {
        $limit = new \ReflectionClassConstant(LanguageGuess::class, 'LONG_TEXT_LENGTH')->getValue();
        self::assertIsInt($limit);

        return $limit;
    }

    private static function reading(Language $detector): SocialEntryLanguage
    {
        return new SocialEntryLanguage(new LanguageGuess($detector), new StopWordVote(LanguageWiring::stopWords()));
    }

    private static function textOfLength(int $length): string
    {
        return str_repeat('x', $length);
    }

    private static function languageOf(string $body, ?string $statedLanguage, string $detectorSays): ?FeedLanguage
    {
        return self::reading(StubbedDetector::saying($detectorSays))
            ->resolveLanguage(self::entry('…', $body, $statedLanguage));
    }

    private static function titled(string $title, string $summary): ParsedEntryModel
    {
        return new ParsedEntryModel('guid', null, Headline::fromFeed($title), null, $summary, '<p>content</p>', null);
    }

    private static function entry(
        string $headline,
        string $summary,
        ?string $statedLanguage,
        string $contentHtml = '<p>content</p>',
    ): ParsedEntryModel {
        return new ParsedEntryModel(
            'guid',
            null,
            Headline::derived($headline),
            null,
            $summary,
            $contentHtml,
            null,
            statedLanguage: $statedLanguage,
        );
    }
}
