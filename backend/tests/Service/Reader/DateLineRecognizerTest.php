<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\DateLineRecognizer;
use PHPUnit\Framework\TestCase;

final class DateLineRecognizerTest extends TestCase
{
    private DateLineRecognizer $recognizer;

    protected function setUp(): void
    {
        $this->recognizer = new DateLineRecognizer();
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
        $built = [];
        $recognizer = new DateLineRecognizer(self::recordingBuilder($built));

        $recognizer->isDateLine('Hamburg 1');
        $recognizer->isDateLine('Hamburg 2');

        self::assertCount(9, $built);
        self::assertSame($built, array_values(array_unique($built)));
    }

    public function testParsesNothingForTextWithoutADigitOrLongerThanTheCap(): void
    {
        $built = [];
        $recognizer = new DateLineRecognizer(self::recordingBuilder($built));

        self::assertFalse($recognizer->isDateLine('Hamburg'));
        self::assertFalse($recognizer->isDateLine(str_repeat('a', 48) . '1'));

        self::assertSame([], $built);
    }

    public function testParsesTextExactlyAtTheCap(): void
    {
        $built = [];

        (new DateLineRecognizer(self::recordingBuilder($built)))->isDateLine(str_repeat('a', 47) . '1');

        self::assertCount(9, $built);
    }

    public function testCountsTheCapInCharactersNotBytes(): void
    {
        $built = [];

        (new DateLineRecognizer(self::recordingBuilder($built)))->isDateLine(str_repeat('ä', 40) . '1');

        self::assertCount(9, $built);
    }

    /**
     * @param list<string> $built
     *
     * @return \Closure(string, int): \IntlDateFormatter
     */
    private static function recordingBuilder(array &$built): \Closure
    {
        return static function (string $locale, int $style) use (&$built): \IntlDateFormatter {
            $built[] = $locale . '|' . $style;

            return new \IntlDateFormatter($locale, $style, \IntlDateFormatter::NONE, 'UTC');
        };
    }
}
