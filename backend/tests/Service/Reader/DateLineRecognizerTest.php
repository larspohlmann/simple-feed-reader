<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\DateLineRecognizer;
use App\Service\Reader\Factory\StrictDateFormatterFactory;
use App\Tests\Support\RecordingDateFormatterFactory;
use PHPUnit\Framework\TestCase;

final class DateLineRecognizerTest extends TestCase
{
    private DateLineRecognizer $recognizer;

    protected function setUp(): void
    {
        $this->recognizer = new DateLineRecognizer(new StrictDateFormatterFactory());
    }

    public function testDateLineMatchesGermanEnglishAndNumericForms(): void
    {
        self::assertTrue($this->recognizer->isDateLine('7. September 2026'));
        self::assertTrue($this->recognizer->isDateLine('07. September 2026'));
        self::assertTrue($this->recognizer->isDateLine('Montag, 7. September 2026'));
        self::assertTrue($this->recognizer->isDateLine('07.09.2026'));
        self::assertTrue($this->recognizer->isDateLine('September 7, 2026'));
        self::assertTrue($this->recognizer->isDateLine('Sep 01, 2026'));
        self::assertTrue($this->recognizer->isDateLine('7 September 2026'));
        self::assertTrue($this->recognizer->isDateLine('2026-09-01'));
        self::assertTrue($this->recognizer->isDateLine('1. März 2026'));
    }

    public function testDateLineRejectsWordsBareMonthsYearsAndSentences(): void
    {
        self::assertFalse($this->recognizer->isDateLine('Im Jahr 2026 passierte viel'));
        self::assertFalse($this->recognizer->isDateLine('Hamburg'));
        self::assertFalse($this->recognizer->isDateLine('September'));
        self::assertFalse($this->recognizer->isDateLine('2026'));
        self::assertFalse($this->recognizer->isDateLine('9 min.'));
        self::assertFalse($this->recognizer->isDateLine('0'));
    }

    public function testDateLineIsAnchoredForTheNumericForm(): void
    {
        self::assertFalse($this->recognizer->isDateLine('x2026-09-01'));
        self::assertFalse($this->recognizer->isDateLine('2026-09-01x'));
    }

    public function testDateLineRejectsADateFollowedByMoreText(): void
    {
        self::assertFalse($this->recognizer->isDateLine('07.09.2026 Hamburg'));
    }

    public function testDateLineRejectsADateThatOnlyALenientParserWouldRollOver(): void
    {
        self::assertFalse($this->recognizer->isDateLine('31.02.2026'));
        self::assertFalse($this->recognizer->isDateLine('32.09.2026'));
    }

    public function testBuildsOneFormatterPerLocaleAndStyleAndReusesItOnEveryLaterCall(): void
    {
        $formatters = new RecordingDateFormatterFactory();
        $recognizer = new DateLineRecognizer($formatters);

        $recognizer->isDateLine('Hamburg 1');
        $recognizer->isDateLine('Hamburg 2');

        self::assertCount(9, $formatters->built());
        self::assertSame($formatters->built(), array_values(array_unique($formatters->built())));
    }

    public function testParsesNothingForTextWithoutADigitOrLongerThanTheCap(): void
    {
        $formatters = new RecordingDateFormatterFactory();
        $recognizer = new DateLineRecognizer($formatters);

        self::assertFalse($recognizer->isDateLine('Hamburg'));
        self::assertFalse($recognizer->isDateLine(str_repeat('a', 48) . '1'));

        self::assertSame([], $formatters->built());
    }

    public function testParsesTextExactlyAtTheCap(): void
    {
        $formatters = new RecordingDateFormatterFactory();

        (new DateLineRecognizer($formatters))->isDateLine(str_repeat('a', 47) . '1');

        self::assertCount(9, $formatters->built());
    }

    public function testCountsTheCapInCharactersNotBytes(): void
    {
        $formatters = new RecordingDateFormatterFactory();

        (new DateLineRecognizer($formatters))->isDateLine(str_repeat('ä', 40) . '1');

        self::assertCount(9, $formatters->built());
    }
}
