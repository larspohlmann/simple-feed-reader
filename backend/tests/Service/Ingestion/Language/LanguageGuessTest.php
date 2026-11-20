<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Tests\Support\StubbedDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageGuessTest extends TestCase
{
    public function testTheDetectorsTopLanguageIsTheGuess(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('fr'));

        self::assertSame(FeedLanguage::French, $guess->detectLanguage('Some text'));
    }

    public function testALanguageOutsideTheFourGetsNoGuess(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('it'));

        self::assertNull($guess->detectLanguage('Some text'));
    }

    public function testADetectorWithoutAnswerGetsNoGuess(): void
    {
        self::assertNull(new LanguageGuess(StubbedDetector::sayingNothing())->detectLanguage('Some text'));
    }

    public function testATextWithoutLettersGetsNoGuessWhateverTheDetectorSays(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('de'));

        self::assertNull($guess->detectLanguage('2031 – 42 % … 12:30!'));
    }

    public function testAnEmptyTextGetsNoGuessWhateverTheDetectorSays(): void
    {
        self::assertNull(new LanguageGuess(StubbedDetector::saying('de'))->detectLanguage(''));
    }

    public function testTheRealDetectorIsWiredToTheGuess(): void
    {
        $guess = new LanguageGuess(new LanguageDetectionFactory()->create());
        $german = 'Der Bundeskanzler sagte am Montag in Berlin, dass die Regierung weitere Hilfen vorbereitet.';

        self::assertSame(FeedLanguage::German, $guess->detectLanguage($german));
    }

    public function testTheRealDetectorGuessesAnEnglishText(): void
    {
        $guess = new LanguageGuess(new LanguageDetectionFactory()->create());
        $english = 'The prime minister said on Monday in London that the government is preparing further support.';

        self::assertSame(FeedLanguage::English, $guess->detectLanguage($english));
    }

    #[DataProvider('textsOutsideTheFour')]
    public function testTheRealDetectorGivesNoGuessForATextOutsideTheFour(string $text): void
    {
        self::assertNull(new LanguageGuess(new LanguageDetectionFactory()->create())->detectLanguage($text));
    }

    /** @return array<string, array{string}> */
    public static function textsOutsideTheFour(): array
    {
        return [
            'Italian' => ['Il presidente del Consiglio ha detto lunedì a Roma che il governo prepara nuove misure.'],
            'Dutch' => ['De premier zei maandag in Den Haag dat de regering nieuwe maatregelen voorbereidt.'],
            'Russian' => ['Президент заявил в понедельник в Москве, что правительство готовит новые меры поддержки.'],
        ];
    }

    public function testAConfidentGuessNeedsALongText(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('fr'));

        self::assertNull($guess->detectConfidentLanguage(str_repeat('a', 149)));
        self::assertSame(FeedLanguage::French, $guess->detectConfidentLanguage(str_repeat('a', 150)));
    }
}
