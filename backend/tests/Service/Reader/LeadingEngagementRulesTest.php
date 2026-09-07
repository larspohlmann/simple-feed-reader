<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingEngagementRules;
use PHPUnit\Framework\TestCase;

final class LeadingEngagementRulesTest extends TestCase
{
    public function testProseNeedsAtLeastTheThresholdOfCharacters(): void
    {
        self::assertFalse(LeadingEngagementRules::isProse(str_repeat('a', 119), 0));
        self::assertTrue(LeadingEngagementRules::isProse(str_repeat('a', 120), 0));
    }

    public function testLinkDominatedTextIsNotProseEvenWhenLongEnough(): void
    {
        $text = str_repeat('a', 200);

        self::assertTrue(LeadingEngagementRules::isProse($text, 159));
        self::assertFalse(LeadingEngagementRules::isProse($text, 160));
    }

    public function testEmojiOnlyRecognizesPictographsIncludingVariationSelectors(): void
    {
        self::assertTrue(LeadingEngagementRules::isEmojiOnly("\u{2764}\u{FE0F}"));
        self::assertTrue(LeadingEngagementRules::isEmojiOnly('❤️😂😱'));
    }

    public function testEmojiOnlyRejectsEmptyAndMixedText(): void
    {
        self::assertFalse(LeadingEngagementRules::isEmojiOnly(''));
        self::assertFalse(LeadingEngagementRules::isEmojiOnly('❤️ Danke'));
        self::assertFalse(LeadingEngagementRules::isEmojiOnly('3'));
    }

    public function testCounterMatchesLocaleSeparatorsAndTheClosedNounSet(): void
    {
        self::assertTrue(LeadingEngagementRules::isCounter('1.251 Klicks'));
        self::assertTrue(LeadingEngagementRules::isCounter('12,345 views'));
        self::assertTrue(LeadingEngagementRules::isCounter('1 251 Reaktionen'));
    }

    public function testCounterLowercasesBeforeMatchingTheNoun(): void
    {
        self::assertTrue(LeadingEngagementRules::isCounter('1.251 KLICKS'));
    }

    public function testCounterRejectsUnknownNounsAndPlainText(): void
    {
        self::assertFalse(LeadingEngagementRules::isCounter('5 photos'));
        self::assertFalse(LeadingEngagementRules::isCounter('Hamburg'));
    }

    public function testBylineMatchesVonOrByRegardlessOfCase(): void
    {
        self::assertTrue(LeadingEngagementRules::isByline('Von Jana Steger'));
        self::assertTrue(LeadingEngagementRules::isByline('BY Jane Doe'));
    }

    public function testBylineIsAnchoredAndNeedsAWordBoundary(): void
    {
        self::assertFalse(LeadingEngagementRules::isByline('Ich zitiere Von Jana'));
        self::assertFalse(LeadingEngagementRules::isByline('Vonnegut'));
    }

    public function testSeparatorOnlyMatchesBareGlyphRuns(): void
    {
        self::assertTrue(LeadingEngagementRules::isSeparatorOnly('|'));
        self::assertTrue(LeadingEngagementRules::isSeparatorOnly('›'));
        self::assertTrue(LeadingEngagementRules::isSeparatorOnly('•'));
    }

    public function testSeparatorOnlyRejectsEmptyAndTextThatCarriesWords(): void
    {
        self::assertFalse(LeadingEngagementRules::isSeparatorOnly(''));
        self::assertFalse(LeadingEngagementRules::isSeparatorOnly('Video'));
        self::assertFalse(LeadingEngagementRules::isSeparatorOnly('a | b'));
    }

    public function testNavigationLabelMatchesShortLinkDominatedText(): void
    {
        self::assertTrue(LeadingEngagementRules::isNavigationLabel('NEWS', 4));
        self::assertTrue(LeadingEngagementRules::isNavigationLabel('heute journal', 13));
    }

    public function testNavigationLabelRejectsLinkThinAndLongText(): void
    {
        self::assertFalse(LeadingEngagementRules::isNavigationLabel('Read our earlier coverage here', 4));
        self::assertFalse(LeadingEngagementRules::isNavigationLabel(str_repeat('a', 130), 130));
    }

    public function testKickerMatchesAVeryShortTextOnlyLabelAboveTheTitle(): void
    {
        self::assertTrue(LeadingEngagementRules::isKicker('Demokratie', 0));
        self::assertTrue(LeadingEngagementRules::isKicker('Kapitalismus', 0));
    }

    public function testKickerRejectsLinksSentencesAndTooManyWords(): void
    {
        self::assertFalse(LeadingEngagementRules::isKicker('Wallpapers', 10));
        self::assertFalse(LeadingEngagementRules::isKicker('It is over.', 0));
        self::assertFalse(LeadingEngagementRules::isKicker('Announcing the winning poems from the challenge', 0));
    }

    public function testKickerNeverSwallowsAByline(): void
    {
        self::assertFalse(LeadingEngagementRules::isKicker('By Jane Doe', 0));
    }

    public function testReadingTimeMatchesTheCommonForms(): void
    {
        self::assertTrue(LeadingEngagementRules::isReadingTime('9 min.'));
        self::assertTrue(LeadingEngagementRules::isReadingTime('11 min read'));
        self::assertTrue(LeadingEngagementRules::isReadingTime('9 minutes'));
    }

    public function testReadingTimeRejectsProseThatMerelyStartsWithAMinute(): void
    {
        self::assertFalse(LeadingEngagementRules::isReadingTime('9 minerals were found'));
        self::assertFalse(LeadingEngagementRules::isReadingTime('min'));
    }

    public function testDateLineMatchesGermanEnglishAndNumericForms(): void
    {
        self::assertTrue(LeadingEngagementRules::isDateLine('7. September 2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('07. September 2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('Montag, 7. September 2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('07.09.2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('September 7, 2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('Sep 01, 2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('7 September 2026'));
        self::assertTrue(LeadingEngagementRules::isDateLine('2026-09-01'));
    }

    public function testDateLineRejectsWordsBareMonthsYearsAndSentences(): void
    {
        self::assertFalse(LeadingEngagementRules::isDateLine('Im Jahr 2026 passierte viel'));
        self::assertFalse(LeadingEngagementRules::isDateLine('Hamburg'));
        self::assertFalse(LeadingEngagementRules::isDateLine('September'));
        self::assertFalse(LeadingEngagementRules::isDateLine('2026'));
        self::assertFalse(LeadingEngagementRules::isDateLine('9 min.'));
        self::assertFalse(LeadingEngagementRules::isDateLine('0'));
    }

    public function testBareNumberMatchesDigitsOnly(): void
    {
        self::assertTrue(LeadingEngagementRules::isBareNumber('0'));
        self::assertTrue(LeadingEngagementRules::isBareNumber('42'));
        self::assertFalse(LeadingEngagementRules::isBareNumber('0 reactions'));
    }

    public function testSeparatorOnlyIsAnchoredAtBothEnds(): void
    {
        self::assertFalse(LeadingEngagementRules::isSeparatorOnly('|abc'));
        self::assertFalse(LeadingEngagementRules::isSeparatorOnly('abc|'));
    }

    public function testReadingTimeIsAnchoredAndCaseInsensitive(): void
    {
        self::assertFalse(LeadingEngagementRules::isReadingTime('lies 9 min'));
        self::assertTrue(LeadingEngagementRules::isReadingTime('9 MIN'));
    }

    public function testBareNumberRejectsDigitsThatFollowLetters(): void
    {
        self::assertFalse(LeadingEngagementRules::isBareNumber('a1'));
    }

    public function testNavigationLabelHonoursItsLengthAndRatioBoundaries(): void
    {
        self::assertTrue(LeadingEngagementRules::isNavigationLabel('AAAAAAAAAA', 8));
        self::assertFalse(LeadingEngagementRules::isNavigationLabel(str_repeat('a', 120), 120));
        self::assertFalse(LeadingEngagementRules::isNavigationLabel('', 0));
    }

    public function testKickerHonoursItsWordAndCharacterCaps(): void
    {
        self::assertTrue(LeadingEngagementRules::isKicker('One Two Three', 0));
        self::assertFalse(LeadingEngagementRules::isKicker('One Two Three Four', 0));
        self::assertFalse(LeadingEngagementRules::isKicker(str_repeat('a', 31), 0));
    }

    public function testDateLineIsAnchoredForTheNumericForm(): void
    {
        self::assertFalse(LeadingEngagementRules::isDateLine('x2026-09-01'));
        self::assertFalse(LeadingEngagementRules::isDateLine('2026-09-01x'));
    }

    public function testHasAuthorTreatsNullAndBlankAsNoAuthor(): void
    {
        self::assertFalse(LeadingEngagementRules::hasAuthor(null));
        self::assertFalse(LeadingEngagementRules::hasAuthor('   '));
        self::assertTrue(LeadingEngagementRules::hasAuthor('Jana'));
    }

    public function testCollapseTrimsAndFoldsRunsOfWhitespace(): void
    {
        self::assertSame('a b', LeadingEngagementRules::collapse("  a \n\t b  "));
        self::assertSame('', LeadingEngagementRules::collapse(null));
    }
}
