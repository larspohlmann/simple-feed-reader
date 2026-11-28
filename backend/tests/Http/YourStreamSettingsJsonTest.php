<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\InterestPhrase;
use App\Entity\YourStreamTuning;
use App\Enum\FeedLanguage;
use App\Enum\YourStreamPrecision;
use App\Http\YourStreamSettingsJson;
use App\Service\Curation\YourStream\Model\YourStreamSettingsStateModel;
use PHPUnit\Framework\TestCase;

final class YourStreamSettingsJsonTest extends TestCase
{
    public function testPhrasesGroupByLanguageInCaseOrderStrongestFirst(): void
    {
        $state = new YourStreamSettingsStateModel(YourStreamTuning::defaults(), [
            new InterestPhrase(FeedLanguage::French, 'vélo', 0.4),
            new InterestPhrase(FeedLanguage::German, 'Wärme', 0.5),
            new InterestPhrase(FeedLanguage::German, 'Akku', 0.9),
            new InterestPhrase(FeedLanguage::German, 'Bahn', 0.9),
        ]);

        self::assertSame([
            ['language' => 'de', 'phrases' => [
                ['phrase' => 'Akku', 'weight' => 0.9],
                ['phrase' => 'Bahn', 'weight' => 0.9],
                ['phrase' => 'Wärme', 'weight' => 0.5],
            ]],
            ['language' => 'fr', 'phrases' => [['phrase' => 'vélo', 'weight' => 0.4]]],
        ], YourStreamSettingsJson::state($state)['phrases']);
    }

    public function testTheSwitchPrecisionAndDefaultPrecisionAreEmitted(): void
    {
        $state = new YourStreamSettingsStateModel(new YourStreamTuning(false, YourStreamPrecision::Narrowest), []);

        $json = YourStreamSettingsJson::state($state);

        self::assertFalse($json['enabled']);
        self::assertSame('narrowest', $json['precision']);
        self::assertSame(YourStreamPrecision::DEFAULT->value, $json['defaultPrecision']);
        self::assertSame([], $json['phrases']);
    }

    public function testAWholeNumberWeightStaysAFloat(): void
    {
        $state = new YourStreamSettingsStateModel(YourStreamTuning::defaults(), [
            new InterestPhrase(FeedLanguage::English, 'heat pumps', 1.0),
        ]);

        self::assertSame(1.0, YourStreamSettingsJson::state($state)['phrases'][0]['phrases'][0]['weight']);
    }
}
