<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language\Factory;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use PHPUnit\Framework\TestCase;

final class LanguageDetectionFactoryTest extends TestCase
{
    private const string ITALIAN = 'La biblioteca comunale resterà chiusa per tutto il mese di agosto, perché gli'
        . ' operai devono rifare il tetto e sistemare le finestre della sala di lettura al primo piano.';

    public function testTheDefaultDetectorNamesALanguageOutsideTheFour(): void
    {
        self::assertSame(
            'it',
            array_key_first((new LanguageDetectionFactory())->create()->detect(self::ITALIAN)->close()),
        );
    }

    public function testTheFeedLanguageDetectorNamesOnlyTheFour(): void
    {
        $scores = (new LanguageDetectionFactory())->createForFeedLanguages()->detect(self::ITALIAN)->close();

        self::assertEqualsCanonicalizing(FeedLanguage::values(), array_keys($scores));
    }
}
