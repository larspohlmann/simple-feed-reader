<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Tests\Support\StubbedDetector;
use PHPUnit\Framework\TestCase;

final class LanguageGuessTest extends TestCase
{
    public function testTheDetectorsTopLanguageIsTheGuess(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('fr'));

        self::assertSame(FeedLanguage::French, $guess->bestFor('Some text'));
    }

    public function testALanguageOutsideTheFourGetsNoGuess(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('it'));

        self::assertNull($guess->bestFor('Some text'));
    }

    public function testADetectorWithoutAnswerGetsNoGuess(): void
    {
        self::assertNull(new LanguageGuess(StubbedDetector::sayingNothing())->bestFor('Some text'));
    }

    public function testATextWithoutLettersGetsNoGuessWhateverTheDetectorSays(): void
    {
        $guess = new LanguageGuess(StubbedDetector::saying('de'));

        self::assertNull($guess->bestFor('2031 – 42 % … 12:30!'));
    }

    public function testAnEmptyTextGetsNoGuessWhateverTheDetectorSays(): void
    {
        self::assertNull(new LanguageGuess(StubbedDetector::saying('de'))->bestFor(''));
    }

    public function testTheRealDetectorIsWiredToTheGuess(): void
    {
        $guess = new LanguageGuess(new LanguageDetectionFactory()->create());
        $german = 'Der Bundeskanzler sagte am Montag in Berlin, dass die Regierung weitere Hilfen vorbereitet.';

        self::assertSame(FeedLanguage::German, $guess->bestFor($german));
    }
}
