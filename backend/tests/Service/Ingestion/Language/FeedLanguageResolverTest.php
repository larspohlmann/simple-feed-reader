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
use PHPUnit\Framework\TestCase;

final class FeedLanguageResolverTest extends TestCase
{
    private const string URL = 'https://news.test/feed.xml';

    private FeedLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FeedLanguageResolver(new LanguageGuess(new LanguageDetectionFactory()->create()));
    }

    public function testADeclaredTagWinsOverDetection(): void
    {
        $language = $this->resolver->languageFor(new Feed(self::URL), self::feed('en-US', self::entries('german')));

        self::assertSame(FeedLanguage::English, $language);
    }

    public function testAnUnknownDeclaredTagFallsThroughToTheGuess(): void
    {
        $language = $this->resolver->languageFor(new Feed(self::URL), self::feed('it', self::entries('french')));

        self::assertSame(FeedLanguage::French, $language);
    }

    public function testAnUndeclaredFeedGetsTheBestGuess(): void
    {
        $language = $this->resolver->languageFor(new Feed(self::URL), self::feed(null, self::entries('spanish')));

        self::assertSame(FeedLanguage::Spanish, $language);
    }

    public function testAFewHeadlinesAreGuessedWithoutAFloor(): void
    {
        $headlines = self::headlinesOnly(\array_slice(self::entries('german'), 0, 1));

        $language = $this->resolver->languageFor(new Feed(self::URL), self::feed(null, $headlines));

        self::assertSame(FeedLanguage::German, $language);
    }

    public function testABlankDeclarationCountsAsUndeclared(): void
    {
        $language = $this->resolver->languageFor(new Feed(self::URL), self::feed('  ', self::entries('english')));

        self::assertSame(FeedLanguage::English, $language);
    }

    public function testAFeedWithoutLettersStaysWithoutALanguage(): void
    {
        self::assertNull($this->resolver->languageFor(new Feed(self::URL), self::feed(null, [['2031 – 42 %', null]])));
    }

    public function testAFeedWithoutEntriesOrDeclarationStaysWithoutALanguage(): void
    {
        self::assertNull($this->resolver->languageFor(new Feed(self::URL), self::feed(null, [])));
    }

    public function testASetLanguageIsKeptWhateverTheBodyDeclaresOrReads(): void
    {
        $feed = new Feed(self::URL);
        $feed->recordTraits($feed->getTraits()->withLanguage(FeedLanguage::French));

        $language = $this->resolver->languageFor($feed, self::feed('de', self::entries('german')));

        self::assertSame(FeedLanguage::French, $language);
    }

    public function testASocialTimelineIsNotJudged(): void
    {
        $feed = new Feed(self::URL);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        self::assertNull($this->resolver->languageFor($feed, self::feed('de', self::entries('german'))));
    }

    /**
     * Each fixture holds one entry per blank-line-separated block: the headline, then the summary.
     *
     * @return list<array{string, ?string}>
     */
    private static function entries(string $language): array
    {
        $fixture = (string) file_get_contents(__DIR__ . '/../../../Fixtures/language/' . $language . '.txt');

        return array_map(
            static function (string $block): array {
                $lines = explode("\n", $block, 2);

                return [$lines[0], $lines[1] ?? null];
            },
            explode("\n\n", trim($fixture)),
        );
    }

    /**
     * @param list<array{string, ?string}> $entries
     *
     * @return list<array{string, ?string}>
     */
    private static function headlinesOnly(array $entries): array
    {
        return array_map(static fn (array $entry): array => [$entry[0], null], $entries);
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
