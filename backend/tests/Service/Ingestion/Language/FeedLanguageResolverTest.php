<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Entity\Headline;
use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\FeedLanguageResolver;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Model\ParsedFeedModel;
use PHPUnit\Framework\TestCase;

final class FeedLanguageResolverTest extends TestCase
{
    private FeedLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FeedLanguageResolver(new LanguageDetectionFactory()->create());
    }

    public function testDeclaredLanguageAgreeingWithTheEntriesIsKept(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed('de', self::entries('german')));

        self::assertSame(FeedLanguage::German, $verdict?->language);
    }

    public function testConfidentDetectionOverrulesAWrongDeclaration(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed('en-US', self::entries('german')));

        self::assertSame(FeedLanguage::German, $verdict?->language);
    }

    public function testTooLittleTextLeavesTheDeclarationStanding(): void
    {
        $twoHeadlines = self::headlinesOnly(\array_slice(self::entries('german'), 0, 2));

        $verdict = $this->resolver->verdictFor(self::feed('en-US', $twoHeadlines));

        self::assertSame(FeedLanguage::English, $verdict?->language);
    }

    public function testFortyWordsAreEnoughToDetect(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed(null, [[self::germanWords(40), null]]));

        self::assertSame(FeedLanguage::German, $verdict?->language);
    }

    public function testThirtyNineWordsAreTooFewToDetect(): void
    {
        self::assertNull($this->resolver->verdictFor(self::feed(null, [[self::germanWords(39), null]])));
    }

    public function testDeclaredLanguageOutsideTheFourWithoutDetectionRecordsNoLanguage(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed('it', self::entries('italian')));

        self::assertNotNull($verdict);
        self::assertNull($verdict->language);
    }

    public function testUnparsableDeclarationWithoutDetectionRecordsNoLanguage(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed('  klingon ', []));

        self::assertNotNull($verdict);
        self::assertNull($verdict->language);
    }

    public function testDeclaredLanguageOutsideTheFourGivesWayToConfidentDetection(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed('it', self::entries('french')));

        self::assertSame(FeedLanguage::French, $verdict?->language);
    }

    public function testUndeclaredFeedIsDetected(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed(null, self::entries('spanish')));

        self::assertSame(FeedLanguage::Spanish, $verdict?->language);
    }

    public function testUndeclaredEnglishFeedIsDetected(): void
    {
        $verdict = $this->resolver->verdictFor(self::feed(null, self::entries('english')));

        self::assertSame(FeedLanguage::English, $verdict?->language);
    }

    public function testUndeclaredFeedWithTooLittleTextHasNoVerdict(): void
    {
        $twoHeadlines = self::headlinesOnly(\array_slice(self::entries('spanish'), 0, 2));

        self::assertNull($this->resolver->verdictFor(self::feed(null, $twoHeadlines)));
    }

    public function testBlankDeclarationCountsAsUndeclared(): void
    {
        self::assertNull($this->resolver->verdictFor(self::feed('  ', [])));
    }

    public function testUndeclaredMixedLanguageFeedHasNoVerdict(): void
    {
        $english = self::entries('english');
        $mixed = [];
        foreach (self::entries('german') as $index => $german) {
            $mixed[] = $english[$index];
            $mixed[] = $german;
        }

        self::assertNull($this->resolver->verdictFor(self::feed(null, $mixed)));
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

    private static function germanWords(int $count): string
    {
        $text = implode(' ', array_merge(...self::entries('german')));
        preg_match_all('/\p{L}+/u', strip_tags($text), $words);

        return implode(' ', \array_slice($words[0], 0, $count));
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
