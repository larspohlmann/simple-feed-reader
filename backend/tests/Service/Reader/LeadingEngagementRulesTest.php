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
