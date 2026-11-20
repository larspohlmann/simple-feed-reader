<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Entity\Feed;
use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Enum\SourceFormat;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\FeedLanguageResolver;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use App\Tests\Support\StubbedDetector;
use PHPUnit\Framework\TestCase;

final class FeedLanguageResolverTest extends TestCase
{
    private const string URL = 'https://news.test/feed.xml';

    public function testADeclaredTagWinsOverDetection(): void
    {
        $language = self::languageOf('de', 'en-US', [['Headline', null]]);

        self::assertSame(FeedLanguage::English, $language);
    }

    public function testAnUnknownDeclaredTagFallsThroughToTheGuess(): void
    {
        $language = self::languageOf('fr', 'it', [['Headline', null]]);

        self::assertSame(FeedLanguage::French, $language);
    }

    public function testAnUndeclaredFeedGetsTheGuess(): void
    {
        $language = self::languageOf('es', null, [['Headline', null]]);

        self::assertSame(FeedLanguage::Spanish, $language);
    }

    public function testABlankDeclarationCountsAsUndeclared(): void
    {
        $language = self::languageOf('en', '  ', [['Headline', null]]);

        self::assertSame(FeedLanguage::English, $language);
    }

    public function testEveryHeadlineAndSummaryIsGuessedFrom(): void
    {
        $detector = StubbedDetector::saying('de');
        $feed = self::feed(null, [['First', 'One'], ['Second', null], ['Third', 'Three']]);

        new FeedLanguageResolver(new LanguageGuess($detector))->resolveLanguage(new Feed(self::URL), $feed);

        self::assertSame(["First\nOne\nSecond\n\nThird\nThree"], $detector->seenTexts());
    }

    public function testTheGuessedTextIsCutAtItsLimit(): void
    {
        $limit = new \ReflectionClassConstant(FeedLanguageResolver::class, 'TEXT_LIMIT')->getValue();
        self::assertIsInt($limit);
        $detector = StubbedDetector::saying('de');
        $feed = self::feed(null, [['Headline', str_repeat('x', $limit * 2)]]);

        new FeedLanguageResolver(new LanguageGuess($detector))->resolveLanguage(new Feed(self::URL), $feed);

        self::assertSame($limit, mb_strlen($detector->seenTexts()[0]));
    }

    public function testTheGuessedTextIsCutAtItsLimitInCharactersNotBytes(): void
    {
        $limit = new \ReflectionClassConstant(FeedLanguageResolver::class, 'TEXT_LIMIT')->getValue();
        self::assertIsInt($limit);
        $detector = StubbedDetector::saying('de');
        $feed = self::feed(null, [['Headline', str_repeat('ä', $limit * 2)]]);

        new FeedLanguageResolver(new LanguageGuess($detector))->resolveLanguage(new Feed(self::URL), $feed);

        self::assertSame($limit, mb_strlen($detector->seenTexts()[0]));
    }

    public function testAFeedTheDetectorCannotPlaceStaysWithoutALanguage(): void
    {
        $language = self::languageOf('it', null, [['Headline', null]]);

        self::assertNull($language);
    }

    public function testAFeedWithoutLettersStaysWithoutALanguage(): void
    {
        $language = self::languageOf('de', null, [['2031 – 42 %', null]]);

        self::assertNull($language);
    }

    public function testAFeedWithoutEntriesOrDeclarationStaysWithoutALanguage(): void
    {
        self::assertNull(self::resolverSaying('de')->resolveLanguage(new Feed(self::URL), self::feed(null, [])));
    }

    public function testASetLanguageIsKeptWhateverTheBodyDeclaresOrReads(): void
    {
        $feed = new Feed(self::URL);
        $feed->recordTraits($feed->getTraits()->withLanguage(FeedLanguage::French));

        $language = self::resolverSaying('es')->resolveLanguage($feed, self::feed('de', [['Headline', null]]));

        self::assertSame(FeedLanguage::French, $language);
    }

    public function testASocialTimelineIsNotJudged(): void
    {
        $feed = new Feed(self::URL);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        self::assertNull(self::resolverSaying('de')->resolveLanguage($feed, self::feed('de', [['Headline', null]])));
    }

    public function testASavedFeedIsNotJudged(): void
    {
        $feed = new Feed(self::URL);
        $feed->setSourceFormat(SourceFormat::SAVED);

        self::assertNull(self::resolverSaying('de')->resolveLanguage($feed, self::feed('de', [['Headline', null]])));
    }

    public function testARealDetectorGuessesAnUnambiguousFeed(): void
    {
        $fixture = (string) file_get_contents(__DIR__ . '/../../../Fixtures/language/german.txt');
        $entries = array_map(
            static fn (string $block): array => self::headlineAndSummary($block),
            explode("\n\n", trim($fixture)),
        );
        $resolver = new FeedLanguageResolver(new LanguageGuess(new LanguageDetectionFactory()->create()));

        self::assertSame(
            FeedLanguage::German,
            $resolver->resolveLanguage(new Feed(self::URL), self::feed(null, $entries)),
        );
    }

    /**
     * @param list<array{string, ?string}> $entries
     */
    private static function languageOf(string $detectorSays, ?string $declaredLanguage, array $entries): ?FeedLanguage
    {
        $resolver = new FeedLanguageResolver(new LanguageGuess(StubbedDetector::saying($detectorSays)));

        return $resolver->resolveLanguage(new Feed(self::URL), self::feed($declaredLanguage, $entries));
    }

    /**
     * @return array{string, ?string}
     */
    private static function headlineAndSummary(string $block): array
    {
        $lines = explode("\n", $block, 2);

        return [$lines[0], $lines[1] ?? null];
    }

    private static function resolverSaying(string $languageCode): FeedLanguageResolver
    {
        return new FeedLanguageResolver(new LanguageGuess(StubbedDetector::saying($languageCode)));
    }

    /**
     * @param list<array{string, ?string}> $entries
     */
    private static function feed(?string $declaredLanguage, array $entries): ParsedFeedModel
    {
        return new ParsedFeedModel(
            'A feed',
            'https://news.test/',
            null,
            null,
            array_map(self::entry(...), $entries, array_keys($entries)),
            declaredLanguage: $declaredLanguage,
        );
    }

    /**
     * @param array{string, ?string} $entry
     */
    private static function entry(array $entry, int $index): ParsedEntryModel
    {
        return new ParsedEntryModel(
            'guid-' . $index,
            'https://news.test/' . $index,
            Headline::fromFeed($entry[0]),
            null,
            $entry[1],
            null,
            null,
        );
    }
}
