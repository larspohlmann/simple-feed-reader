<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingEngagementRules;
use PHPUnit\Framework\TestCase;

final class LeadingEngagementRulesTest extends TestCase
{
    public function testProseNeedsAtLeastTheThresholdOfCharacters(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isProse(str_repeat('a', 119), 0));
        self::assertTrue((new LeadingEngagementRules())->isProse(str_repeat('a', 120), 0));
    }

    public function testLinkDominatedTextIsNotProseEvenWhenLongEnough(): void
    {
        $text = str_repeat('a', 200);

        self::assertTrue((new LeadingEngagementRules())->isProse($text, 159));
        self::assertFalse((new LeadingEngagementRules())->isProse($text, 160));
    }

    public function testEmojiOnlyRecognizesPictographsIncludingVariationSelectors(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isEmojiOnly("\u{2764}\u{FE0F}"));
        self::assertTrue((new LeadingEngagementRules())->isEmojiOnly('❤️😂😱'));
    }

    public function testEmojiOnlyRejectsEmptyAndMixedText(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isEmojiOnly(''));
        self::assertFalse((new LeadingEngagementRules())->isEmojiOnly('❤️ Danke'));
        self::assertFalse((new LeadingEngagementRules())->isEmojiOnly('3'));
    }

    public function testCounterMatchesLocaleSeparatorsAndTheClosedNounSet(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isCounter('1.251 Klicks'));
        self::assertTrue((new LeadingEngagementRules())->isCounter('12,345 views'));
        self::assertTrue((new LeadingEngagementRules())->isCounter('1 251 Reaktionen'));
    }

    public function testCounterLowercasesBeforeMatchingTheNoun(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isCounter('1.251 KLICKS'));
    }

    public function testCounterRejectsUnknownNounsAndPlainText(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isCounter('5 photos'));
        self::assertFalse((new LeadingEngagementRules())->isCounter('Hamburg'));
    }

    public function testBylineMatchesVonOrByRegardlessOfCase(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isByline('Von Jana Steger'));
        self::assertTrue((new LeadingEngagementRules())->isByline('BY Jane Doe'));
    }

    public function testBylineIsAnchoredAndNeedsAWordBoundary(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isByline('Ich zitiere Von Jana'));
        self::assertFalse((new LeadingEngagementRules())->isByline('Vonnegut'));
    }

    public function testSeparatorOnlyMatchesBareGlyphRuns(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isSeparatorOnly('|'));
        self::assertTrue((new LeadingEngagementRules())->isSeparatorOnly('›'));
        self::assertTrue((new LeadingEngagementRules())->isSeparatorOnly('•'));
    }

    public function testSeparatorOnlyRejectsEmptyAndTextThatCarriesWords(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isSeparatorOnly(''));
        self::assertFalse((new LeadingEngagementRules())->isSeparatorOnly('Video'));
        self::assertFalse((new LeadingEngagementRules())->isSeparatorOnly('a | b'));
    }

    public function testNavigationLabelMatchesShortLinkDominatedText(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isNavigationLabel('NEWS', 4));
        self::assertTrue((new LeadingEngagementRules())->isNavigationLabel('heute journal', 13));
    }

    public function testNavigationLabelRejectsLinkThinAndLongText(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isNavigationLabel('Read our earlier coverage here', 4));
        self::assertFalse((new LeadingEngagementRules())->isNavigationLabel(str_repeat('a', 130), 130));
    }

    public function testKickerMatchesAVeryShortTextOnlyLabelAboveTheTitle(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isKicker('Demokratie', 0));
        self::assertTrue((new LeadingEngagementRules())->isKicker('Kapitalismus', 0));
    }

    public function testKickerRejectsLinksSentencesAndTooManyWords(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isKicker('Wallpapers', 10));
        self::assertFalse((new LeadingEngagementRules())->isKicker('It is over.', 0));
        self::assertFalse(
            (new LeadingEngagementRules())->isKicker('Announcing the winning poems from the challenge', 0),
        );
    }

    public function testKickerNeverSwallowsAByline(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isKicker('By Jane Doe', 0));
    }

    public function testReadingTimeMatchesTheCommonForms(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isReadingTime('9 min.'));
        self::assertTrue((new LeadingEngagementRules())->isReadingTime('11 min read'));
        self::assertTrue((new LeadingEngagementRules())->isReadingTime('9 minutes'));
    }

    public function testReadingTimeRejectsProseThatMerelyStartsWithAMinute(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isReadingTime('9 minerals were found'));
        self::assertFalse((new LeadingEngagementRules())->isReadingTime('min'));
    }

    public function testBareNumberMatchesDigitsOnly(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isBareNumber('0'));
        self::assertTrue((new LeadingEngagementRules())->isBareNumber('42'));
        self::assertFalse((new LeadingEngagementRules())->isBareNumber('0 reactions'));
    }

    public function testSeparatorOnlyIsAnchoredAtBothEnds(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isSeparatorOnly('|abc'));
        self::assertFalse((new LeadingEngagementRules())->isSeparatorOnly('abc|'));
    }

    public function testReadingTimeIsAnchoredAndCaseInsensitive(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isReadingTime('lies 9 min'));
        self::assertTrue((new LeadingEngagementRules())->isReadingTime('9 MIN'));
    }

    public function testBareNumberRejectsDigitsThatFollowLetters(): void
    {
        self::assertFalse((new LeadingEngagementRules())->isBareNumber('a1'));
    }

    public function testNavigationLabelHonoursItsLengthAndRatioBoundaries(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isNavigationLabel('AAAAAAAAAA', 8));
        self::assertFalse((new LeadingEngagementRules())->isNavigationLabel(str_repeat('a', 120), 120));
        self::assertFalse((new LeadingEngagementRules())->isNavigationLabel('', 0));
    }

    public function testKickerHonoursItsWordAndCharacterCaps(): void
    {
        self::assertTrue((new LeadingEngagementRules())->isKicker('One Two Three', 0));
        self::assertFalse((new LeadingEngagementRules())->isKicker('One Two Three Four', 0));
        self::assertFalse((new LeadingEngagementRules())->isKicker(str_repeat('a', 31), 0));
    }

    public function testHasAuthorTreatsNullAndBlankAsNoAuthor(): void
    {
        self::assertFalse((new LeadingEngagementRules())->hasAuthor(null));
        self::assertFalse((new LeadingEngagementRules())->hasAuthor('   '));
        self::assertTrue((new LeadingEngagementRules())->hasAuthor('Jana'));
    }
}
