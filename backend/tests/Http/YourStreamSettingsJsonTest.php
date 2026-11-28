<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Entity\YourStreamTuning;
use App\Entity\YourStreamWord;
use App\Enum\FeedLanguage;
use App\Enum\YourStreamPrecision;
use App\Http\YourStreamSettingsJson;
use App\Service\Curation\YourStream\Model\YourStreamSettingsStateModel;
use PHPUnit\Framework\TestCase;

final class YourStreamSettingsJsonTest extends TestCase
{
    public function testWordsGroupByLanguageInCaseOrderStrongestFirstThenAlphabetical(): void
    {
        $state = self::stateWith([
            self::word(FeedLanguage::French, 'velo', 'vélo', 0.4),
            self::word(FeedLanguage::German, 'warm', 'Wärme', 0.5),
            self::word(FeedLanguage::German, 'bahn', 'Bahn', 0.9),
            self::word(FeedLanguage::German, 'akku', 'Akku', 0.9),
        ]);

        self::assertSame([
            ['language' => 'de', 'words' => [
                ['word' => 'Akku', 'weight' => 0.9],
                ['word' => 'Bahn', 'weight' => 0.9],
                ['word' => 'Wärme', 'weight' => 0.5],
            ]],
            ['language' => 'fr', 'words' => [['word' => 'vélo', 'weight' => 0.4]]],
        ], YourStreamSettingsJson::state($state)['words']);
    }

    public function testEqualWordsInALanguageShowOnceAtTheirHighestWeight(): void
    {
        $state = self::stateWith([
            self::word(FeedLanguage::English, 'climat', 'climate change', 0.6),
            self::word(FeedLanguage::English, 'chang', 'climate change', 0.4),
        ]);

        self::assertSame(
            [['language' => 'en', 'words' => [['word' => 'climate change', 'weight' => 0.6]]]],
            YourStreamSettingsJson::state($state)['words'],
        );
    }

    public function testTheSameWordInTwoLanguagesShowsInEach(): void
    {
        $state = self::stateWith([
            self::word(FeedLanguage::English, 'solar', 'solar', 1.0),
            self::word(FeedLanguage::German, 'solar', 'solar', 0.5),
        ]);

        self::assertSame(
            ['de', 'en'],
            array_column(YourStreamSettingsJson::state($state)['words'], 'language'),
        );
    }

    public function testCutFormWordsComeLastWithoutALanguage(): void
    {
        $state = self::stateWith([
            self::word(null, 'bundes', 'Bundesliga', 0.5),
            self::word(FeedLanguage::English, 'solar', 'solar', 1.0),
        ]);

        $words = YourStreamSettingsJson::state($state)['words'];

        self::assertSame(['en', null], array_column($words, 'language'));
        self::assertSame([['word' => 'Bundesliga', 'weight' => 0.5]], $words[1]['words']);
    }

    public function testTheSwitchPrecisionAndDefaultPrecisionAreEmitted(): void
    {
        $state = new YourStreamSettingsStateModel(new YourStreamTuning(false, YourStreamPrecision::Narrowest), []);

        $json = YourStreamSettingsJson::state($state);

        self::assertFalse($json['enabled']);
        self::assertSame('narrowest', $json['precision']);
        self::assertSame(YourStreamPrecision::DEFAULT->value, $json['defaultPrecision']);
        self::assertSame([], $json['words']);
    }

    public function testAWholeNumberWeightStaysAFloat(): void
    {
        $state = self::stateWith([self::word(FeedLanguage::English, 'heat', 'heat pumps', 1.0)]);

        self::assertSame(1.0, YourStreamSettingsJson::state($state)['words'][0]['words'][0]['weight']);
    }

    /** @param list<YourStreamWord> $words */
    private static function stateWith(array $words): YourStreamSettingsStateModel
    {
        return new YourStreamSettingsStateModel(YourStreamTuning::defaults(), $words);
    }

    private static function word(?FeedLanguage $language, string $stem, string $word, float $weight): YourStreamWord
    {
        return new YourStreamWord(
            new User('json@example.com', new \DateTimeImmutable('2026-07-01')),
            $language,
            $stem,
            $word,
            $weight,
            1.0,
        );
    }
}
