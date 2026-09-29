<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingEngagementRules;
use PHPUnit\Framework\TestCase;

final class LeadingEngagementRulesTest extends TestCase
{
    private LeadingEngagementRules $rules;

    protected function setUp(): void
    {
        $this->rules = new LeadingEngagementRules();
    }

    public function testProseNeedsAtLeastTheThresholdOfCharacters(): void
    {
        self::assertFalse($this->rules->isProse(str_repeat('a', 119), 0));
        self::assertTrue($this->rules->isProse(str_repeat('a', 120), 0));
    }

    public function testLinkDominatedTextIsNotProseEvenWhenLongEnough(): void
    {
        $text = str_repeat('a', 200);

        self::assertTrue($this->rules->isProse($text, 159));
        self::assertFalse($this->rules->isProse($text, 160));
    }

    public function testEmojiOnlyRecognizesPictographsIncludingVariationSelectors(): void
    {
        self::assertTrue($this->rules->isEmojiOnly("\u{2764}\u{FE0F}"));
        self::assertTrue($this->rules->isEmojiOnly('❤️😂😱'));
    }

    public function testEmojiOnlyRejectsEmptyAndMixedText(): void
    {
        self::assertFalse($this->rules->isEmojiOnly(''));
        self::assertFalse($this->rules->isEmojiOnly('❤️ Danke'));
        self::assertFalse($this->rules->isEmojiOnly('3'));
    }

    public function testCounterMatchesLocaleSeparatorsAndTheClosedNounSet(): void
    {
        self::assertTrue($this->rules->isCounter('1.251 Klicks'));
        self::assertTrue($this->rules->isCounter('12,345 views'));
        self::assertTrue($this->rules->isCounter('1 251 Reaktionen'));
    }

    public function testCounterLowercasesBeforeMatchingTheNoun(): void
    {
        self::assertTrue($this->rules->isCounter('1.251 KLICKS'));
    }

    public function testCounterRejectsUnknownNounsAndPlainText(): void
    {
        self::assertFalse($this->rules->isCounter('5 photos'));
        self::assertFalse($this->rules->isCounter('Hamburg'));
    }

    public function testBylineMatchesVonOrByRegardlessOfCase(): void
    {
        self::assertTrue($this->rules->isByline('Von Jana Steger'));
        self::assertTrue($this->rules->isByline('BY Jane Doe'));
    }

    public function testBylineIsAnchoredAndNeedsAWordBoundary(): void
    {
        self::assertFalse($this->rules->isByline('Ich zitiere Von Jana'));
        self::assertFalse($this->rules->isByline('Vonnegut'));
    }

    public function testSeparatorOnlyMatchesBareGlyphRuns(): void
    {
        self::assertTrue($this->rules->isSeparatorOnly('|'));
        self::assertTrue($this->rules->isSeparatorOnly('›'));
        self::assertTrue($this->rules->isSeparatorOnly('•'));
    }

    public function testSeparatorOnlyRejectsEmptyAndTextThatCarriesWords(): void
    {
        self::assertFalse($this->rules->isSeparatorOnly(''));
        self::assertFalse($this->rules->isSeparatorOnly('Video'));
        self::assertFalse($this->rules->isSeparatorOnly('a | b'));
    }

    public function testNavigationLabelMatchesShortLinkDominatedText(): void
    {
        self::assertTrue($this->rules->isNavigationLabel('NEWS', 4));
        self::assertTrue($this->rules->isNavigationLabel('heute journal', 13));
    }

    public function testNavigationLabelRejectsLinkThinAndLongText(): void
    {
        self::assertFalse($this->rules->isNavigationLabel('Read our earlier coverage here', 4));
        self::assertFalse($this->rules->isNavigationLabel(str_repeat('a', 130), 130));
    }

    public function testKickerMatchesAVeryShortTextOnlyLabelAboveTheTitle(): void
    {
        self::assertTrue($this->rules->isKicker('Demokratie', 0));
        self::assertTrue($this->rules->isKicker('Kapitalismus', 0));
    }

    public function testKickerRejectsLinksSentencesAndTooManyWords(): void
    {
        self::assertFalse($this->rules->isKicker('Wallpapers', 10));
        self::assertFalse($this->rules->isKicker('It is over.', 0));
        self::assertFalse(
            $this->rules->isKicker('Announcing the winning poems from the challenge', 0),
        );
    }

    public function testKickerNeverSwallowsAByline(): void
    {
        self::assertFalse($this->rules->isKicker('By Jane Doe', 0));
    }

    public function testReadingTimeMatchesTheCommonForms(): void
    {
        self::assertTrue($this->rules->isReadingTime('9 min.'));
        self::assertTrue($this->rules->isReadingTime('11 min read'));
        self::assertTrue($this->rules->isReadingTime('9 minutes'));
    }

    public function testReadingTimeRejectsProseThatMerelyStartsWithAMinute(): void
    {
        self::assertFalse($this->rules->isReadingTime('9 minerals were found'));
        self::assertFalse($this->rules->isReadingTime('min'));
    }

    public function testBareNumberMatchesDigitsOnly(): void
    {
        self::assertTrue($this->rules->isBareNumber('0'));
        self::assertTrue($this->rules->isBareNumber('42'));
        self::assertFalse($this->rules->isBareNumber('0 reactions'));
    }

    public function testSeparatorOnlyIsAnchoredAtBothEnds(): void
    {
        self::assertFalse($this->rules->isSeparatorOnly('|abc'));
        self::assertFalse($this->rules->isSeparatorOnly('abc|'));
    }

    public function testReadingTimeIsAnchoredAndCaseInsensitive(): void
    {
        self::assertFalse($this->rules->isReadingTime('lies 9 min'));
        self::assertTrue($this->rules->isReadingTime('9 MIN'));
    }

    public function testBareNumberRejectsDigitsThatFollowLetters(): void
    {
        self::assertFalse($this->rules->isBareNumber('a1'));
    }

    public function testNavigationLabelHonoursItsLengthAndRatioBoundaries(): void
    {
        self::assertTrue($this->rules->isNavigationLabel('AAAAAAAAAA', 8));
        self::assertFalse($this->rules->isNavigationLabel(str_repeat('a', 120), 120));
        self::assertFalse($this->rules->isNavigationLabel('', 0));
    }

    public function testKickerHonoursItsWordAndCharacterCaps(): void
    {
        self::assertTrue($this->rules->isKicker('One Two Three', 0));
        self::assertFalse($this->rules->isKicker('One Two Three Four', 0));
        self::assertFalse($this->rules->isKicker(str_repeat('a', 31), 0));
    }

    public function testHasAuthorTreatsNullAndBlankAsNoAuthor(): void
    {
        self::assertFalse($this->rules->hasAuthor(null));
        self::assertFalse($this->rules->hasAuthor('   '));
        self::assertTrue($this->rules->hasAuthor('Jana'));
    }
}
