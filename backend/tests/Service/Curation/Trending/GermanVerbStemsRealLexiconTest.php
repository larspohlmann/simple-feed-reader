<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Factory\SnowballStemmerFactory;
use App\Service\Curation\Trending\GermanLexicon\BundledGermanLexicon;
use App\Service\Curation\Trending\GermanVerbStems;
use App\Service\Curation\Trending\HeadlineStemmer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Records what the shipped lexicon does to the -t rule's examples; a changed row means the data changed (#2230). */
final class GermanVerbStemsRealLexiconTest extends TestCase
{
    private HeadlineStemmer $stemmer;

    protected function setUp(): void
    {
        $lexicon = new BundledGermanLexicon(\dirname(__DIR__, 4) . '/resources/german-lexicon');
        $this->stemmer = new HeadlineStemmer(new SnowballStemmerFactory(), new GermanVerbStems($lexicon));
    }

    #[DataProvider('forms')]
    public function testRecordsWhetherAVerbFormJoinsItsInfinitive(string $form, string $infinitive, bool $joins): void
    {
        $formStem = $this->stemmer->stem($form, FeedLanguage::German);
        $infinitiveStem = $this->stemmer->stem($infinitive, FeedLanguage::German);

        self::assertSame($joins, $formStem === $infinitiveStem, "$form → $formStem, $infinitive → $infinitiveStem");
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function forms(): iterable
    {
        yield 'verhandelt joins' => ['verhandelt', 'verhandeln', true];
        yield 'kündigt stays apart: kundigt is itself a verb stem' => ['kündigt', 'kündigen', false];
        yield 'arbeitet stays apart: arbeit is no verb stem' => ['arbeitet', 'arbeiten', false];
        yield 'nominiert joins nominieren, both at nomini' => ['nominiert', 'nominieren', true];
        yield 'nominiert stays apart from Nominierung: nominier' => ['nominiert', 'Nominierung', false];
        yield 'macht stays apart: machen is not joined' => ['macht', 'machen', false];
        yield 'bett stays apart from beten' => ['bett', 'beten', false];
        yield 'wählt stays apart: wahlt is itself a verb stem' => ['wählt', 'wählen', false];
        yield 'fordert stays apart: fordert is itself a verb stem' => ['fordert', 'fordern', false];
        yield 'warnt stays apart: warnt is itself a verb stem' => ['warnt', 'warnen', false];
    }
}
