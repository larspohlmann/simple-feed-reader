<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Enum\FeedLanguage;
use App\Service\Curation\Recommendation\Stream\InterestTermStems;
use App\Service\Curation\Recommendation\Stream\Model\InterestTermModel;
use App\Service\Curation\Recommendation\Stream\Model\TermStemModel;
use App\Service\Foundation\Stemming\StopWordStems;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Stemming\WordStemmer;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\TestCase;

final class InterestTermStemsTest extends TestCase
{
    private InterestTermStems $stems;
    private WordStemmer $stemmer;
    private StopWordStems $stopWordStems;

    protected function setUp(): void
    {
        $this->stemmer = StemmingWiring::stemmer();
        $this->stopWordStems = StemmingWiring::stopWordStems();
        $this->stems = new InterestTermStems(StemmingWiring::equivalents(), $this->stopWordStems);
    }

    public function testATermIsStoredInItsLanguageAndInTheCutForm(): void
    {
        $stems = $this->stems->buildTermStems([new InterestTermModel(FeedLanguage::English, 'climate', 0.8)]);

        self::assertEqualsCanonicalizing([
            [FeedLanguage::English, $this->stemmer->stem('climate', FeedLanguage::English), 0.8],
            [null, WordStems::cut('climate'), 0.8],
        ], self::rows($stems));
    }

    public function testAPhraseSplitsIntoWordsThatEachCarryTheTermsWeight(): void
    {
        $stems = $this->stems->buildTermStems([new InterestTermModel(FeedLanguage::English, 'heat pump policy', 0.6)]);

        $expected = [];
        foreach (['heat', 'pump', 'policy'] as $word) {
            $expected[] = [FeedLanguage::English, $this->stemmer->stem($word, FeedLanguage::English), 0.6];
            $expected[] = [null, WordStems::cut($word), 0.6];
        }
        self::assertEqualsCanonicalizing($expected, self::rows($stems));
    }

    public function testAGermanCompoundsPartsCarryHalfTheWeight(): void
    {
        $stems = $this->stems->buildTermStems([new InterestTermModel(FeedLanguage::German, 'Feuerwehreinsatz', 1.0)]);

        self::assertEqualsCanonicalizing([
            [FeedLanguage::German, $this->stemmer->stem('Feuerwehreinsatz', FeedLanguage::German), 1.0],
            [FeedLanguage::German, $this->stemmer->stem('Feuerwehr', FeedLanguage::German), 0.5],
            [FeedLanguage::German, $this->stemmer->stem('Einsatz', FeedLanguage::German), 0.5],
            [null, WordStems::cut('Feuerwehreinsatz'), 1.0],
        ], self::rows($stems));
    }

    public function testStopWordStemsAreDropped(): void
    {
        $stems = $this->stems->buildTermStems([
            new InterestTermModel(FeedLanguage::English, 'the energy of the future', 0.7),
        ]);

        self::assertNotSame([], $stems);
        foreach ($stems as $stem) {
            self::assertFalse($this->stopWordStems->isStopWordStem($stem->stem, $stem->language));
        }
    }

    public function testADuplicateStemKeepsItsHighestWeight(): void
    {
        $stems = $this->stems->buildTermStems([
            new InterestTermModel(FeedLanguage::English, 'solar', 0.4),
            new InterestTermModel(FeedLanguage::English, 'solar power', 0.9),
        ]);

        $solar = $this->stemmer->stem('solar', FeedLanguage::English);
        $matching = array_filter(
            $stems,
            static fn (TermStemModel $stem): bool => $stem->language === FeedLanguage::English
                && $stem->stem === $solar,
        );
        self::assertCount(1, $matching);
        self::assertSame(0.9, array_values($matching)[0]->weight);
    }

    public function testAnInstitutionsSpellingsShareTheCanonicalStem(): void
    {
        $english = $this->stems->buildTermStems([new InterestTermModel(FeedLanguage::English, 'European Union', 0.5)]);
        $german = $this->stems->buildTermStems([new InterestTermModel(FeedLanguage::German, 'Europäische Union', 0.5)]);

        self::assertContains([FeedLanguage::English, 'eunion', 0.5], self::rows($english));
        self::assertContains([FeedLanguage::German, 'eunion', 0.5], self::rows($german));
    }

    /**
     * @param list<TermStemModel> $stems
     *
     * @return list<array{?FeedLanguage, string, float}>
     */
    private static function rows(array $stems): array
    {
        return array_map(
            static fn (TermStemModel $stem): array => [$stem->language, $stem->stem, $stem->weight],
            $stems,
        );
    }
}
