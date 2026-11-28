<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\YourStream\Pass;

use App\Enum\FeedLanguage;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\Model\StreamEntryModel;
use App\Service\Curation\YourStream\Pass\EntryStemming;
use App\Service\Foundation\Stemming\Support\WordStems;
use App\Service\Foundation\Stemming\WordStemmer;
use App\Tests\Support\StemmingWiring;
use PHPUnit\Framework\TestCase;

final class EntryStemmingTest extends TestCase
{
    private EntryStemming $stemming;
    private WordStemmer $stemmer;

    protected function setUp(): void
    {
        $this->stemmer = StemmingWiring::stemmer();
        $this->stemming = (new EntryStemmingFactory(
            StemmingWiring::equivalents(),
            StemmingWiring::stopWordStems(),
        ))->create();
    }

    public function testHeadlineStemsWeighOneAndBodyOnlyStemsHalf(): void
    {
        $stems = $this->stemming->collectStems(
            new StreamEntryModel(1, FeedLanguage::English, 'Solar boom', 'Wind farms grow'),
        );

        self::assertEquals([
            $this->stem('solar') => 1.0,
            $this->stem('boom') => 1.0,
            $this->stem('wind') => 0.5,
            $this->stem('farms') => 0.5,
            $this->stem('grow') => 0.5,
        ], $stems->positionWeights);
        self::assertSame(FeedLanguage::English, $stems->language);
    }

    public function testAStemInBothPlacesCountsOnceAtTheHeadlineWeight(): void
    {
        $stems = $this->stemming->collectStems(
            new StreamEntryModel(1, FeedLanguage::English, 'Solar boom', 'Solar panels everywhere'),
        );

        self::assertSame(1.0, $stems->positionWeights[$this->stem('solar')]);
        self::assertSame(0.5, $stems->positionWeights[$this->stem('panels')]);
    }

    public function testAGermanCompoundYieldsItsPartsToo(): void
    {
        $stems = $this->stemming->collectStems(
            new StreamEntryModel(1, FeedLanguage::German, 'Feuerwehreinsatz', null),
        );

        foreach (['Feuerwehreinsatz', 'Feuerwehr', 'Einsatz'] as $word) {
            self::assertSame(1.0, $stems->positionWeights[$this->stemmer->stem($word, FeedLanguage::German)]);
        }
    }

    public function testStopWordsLeaveNoStem(): void
    {
        $stems = $this->stemming->collectStems(
            new StreamEntryModel(1, FeedLanguage::English, 'The future of the energy', 'and the rest'),
        );

        self::assertEqualsCanonicalizing(
            [$this->stem('future'), $this->stem('energy'), $this->stem('rest')],
            array_keys($stems->positionWeights),
        );
    }

    public function testAnEntryWithoutALanguageGetsCutStems(): void
    {
        $stems = $this->stemming->collectStems(new StreamEntryModel(1, null, 'Photovoltaik', null));

        self::assertNull($stems->language);
        self::assertSame([WordStems::cut('Photovoltaik') => 1.0], $stems->positionWeights);
    }

    public function testMarkupInTheSummaryIsStrippedAndCutAtSixHundredCharacters(): void
    {
        $filler = str_repeat('padding ', 100);
        $stems = $this->stemming->collectStems(new StreamEntryModel(
            1,
            FeedLanguage::English,
            'Title',
            '<p>Kept <b>words</b> ' . $filler . ' beyond</p><script>hidden</script>',
        ));

        self::assertArrayHasKey($this->stem('words'), $stems->positionWeights);
        self::assertArrayNotHasKey($this->stem('beyond'), $stems->positionWeights);
        self::assertArrayNotHasKey('p', $stems->positionWeights);
    }

    public function testStemWordsCarryTheSurfaceWordsTheirStemCameFrom(): void
    {
        $words = $this->stemming->collectStemWords(
            new StreamEntryModel(1, FeedLanguage::German, 'Bundesliga-Spieltag', 'Die Bundesliga ruht'),
        );

        self::assertSame(
            ['Bundesliga', 'Bundesliga'],
            $words[$this->stemmer->stem('bundesliga', FeedLanguage::German)],
        );
        self::assertArrayNotHasKey('die', $words);
    }

    public function testACollapsedPhraseLeavesItsStemWithoutASurface(): void
    {
        $entry = new StreamEntryModel(1, FeedLanguage::English, 'European Union summit', null);

        self::assertArrayNotHasKey('eunion', $this->stemming->collectStemWords($entry));
        self::assertArrayHasKey('eunion', $this->stemming->collectStems($entry)->positionWeights);
    }

    private function stem(string $word): string
    {
        return $this->stemmer->stem($word, FeedLanguage::English);
    }
}
