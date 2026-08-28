<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Exception\ValidationException;
use App\Service\Search\SearchMode;
use App\Service\Search\SearchTerms;
use PHPUnit\Framework\TestCase;

final class SearchTermsTest extends TestCase
{
    public function testSplitsTheInputOnWhitespace(): void
    {
        $terms = SearchTerms::fromInput('angular signals');

        self::assertSame(['angular', 'signals'], $terms->terms);
    }

    public function testDropsLeadingAndTrailingWhitespace(): void
    {
        $terms = SearchTerms::fromInput("  angular\t");

        self::assertSame(['angular'], $terms->terms);
    }

    public function testCollapsesRepeatedWhitespaceBetweenTerms(): void
    {
        $terms = SearchTerms::fromInput("angular \n  signals");

        self::assertSame(['angular', 'signals'], $terms->terms);
    }

    public function testRejectsAnInputShorterThanThreeCharacters(): void
    {
        try {
            SearchTerms::fromInput('  ng  ');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Search for at least 3 characters.'], $exception->errors['q'] ?? null);
        }
    }

    public function testAcceptsAnInputOfExactlyThreeCharacters(): void
    {
        $terms = SearchTerms::fromInput('ng2');

        self::assertSame(['ng2'], $terms->terms);
    }

    public function testRejectsATwoCharacterMultibyteInputAsTooShort(): void
    {
        // "üö" is 2 characters (below the 3-character minimum) but 4 bytes —
        // long enough that a byte-counting strlen() would wrongly accept it.
        // The rule is measured in characters, via mb_strlen.
        $this->expectException(ValidationException::class);

        SearchTerms::fromInput('üö');
    }

    public function testRejectsAnInputLongerThanOneHundredCharacters(): void
    {
        try {
            SearchTerms::fromInput(str_repeat('a', 101));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Search for at most 100 characters.'], $exception->errors['q'] ?? null);
        }
    }

    public function testAcceptsExactlyOneHundredMultibyteCharacters(): void
    {
        // 100 "ü" characters is exactly at the character-count ceiling, but
        // 200 bytes — well past it. A byte-counting strlen(), or a boundary
        // widened from > to >=, would both wrongly reject this input; only
        // mb_strlen() with a strict > gets it right.
        $terms = SearchTerms::fromInput(str_repeat('ü', 100));

        self::assertCount(1, $terms->terms);
    }

    public function testKeepsOnlyTheFirstSixTerms(): void
    {
        $terms = SearchTerms::fromInput('one two three four five six seven eight');

        self::assertSame(['one', 'two', 'three', 'four', 'five', 'six'], $terms->terms);
    }

    public function testAnInputWithoutTrailingWhitespaceIsSubstringMode(): void
    {
        $terms = SearchTerms::fromInput('punk');

        self::assertFalse($terms->isWholeWord);
    }

    public function testATrailingSpaceSwitchesToWholeWordMode(): void
    {
        $terms = SearchTerms::fromInput('punk ');

        self::assertTrue($terms->isWholeWord);
        self::assertSame(['punk'], $terms->terms);
    }

    public function testATrailingTabSwitchesToWholeWordMode(): void
    {
        $terms = SearchTerms::fromInput("punk\t");

        self::assertTrue($terms->isWholeWord);
    }

    public function testATrailingNewlineSwitchesToWholeWordMode(): void
    {
        $terms = SearchTerms::fromInput("punk\n");

        self::assertTrue($terms->isWholeWord);
    }

    public function testWholeWordModeAppliesToEveryTermInTheQuery(): void
    {
        $terms = SearchTerms::fromInput('die neue studie ');

        self::assertTrue($terms->isWholeWord);
        self::assertSame(['die', 'neue', 'studie'], $terms->terms);
    }

    public function testTheLengthFloorIsMeasuredOnTheTrimmedInput(): void
    {
        // "ab " is 3 raw characters but trims to 2, below the 3-character
        // floor — the trailing space changes the mode, not the length rule.
        try {
            SearchTerms::fromInput('ab ');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(['Search for at least 3 characters.'], $exception->errors['q'] ?? null);
        }
    }

    // A trailing no-break space (U+00A0) — left behind by a paste or an
    // autocorrect — must be read exactly like a trailing plain space: the
    // frontend's own trailing-space check runs on JavaScript's `\s`, which
    // already treats an NBSP as whitespace, so the server disagreeing would
    // silently strand a search (#408 follow-up).
    public function testATrailingNoBreakSpaceSwitchesToWholeWordMode(): void
    {
        $terms = SearchTerms::fromInput("punk\u{00A0}");

        self::assertTrue($terms->isWholeWord);
        self::assertSame(['punk'], $terms->terms);
    }

    public function testAnInnerNoBreakSpaceSplitsTermsLikeAnyOtherWhitespace(): void
    {
        $terms = SearchTerms::fromInput("daft\u{00A0}punk");

        self::assertSame(['daft', 'punk'], $terms->terms);
        self::assertFalse($terms->isWholeWord);
    }

    public function testAQueryWrappedInDoubleQuotesIsOnePhrasePhrase(): void
    {
        $terms = SearchTerms::fromInput('"climate change"');

        self::assertTrue($terms->isPhrase);
        self::assertFalse($terms->isWholeWord);
        self::assertSame(['climate change'], $terms->terms);
    }

    public function testAPhrasePhraseCollapsesItsInnerWhitespace(): void
    {
        $terms = SearchTerms::fromInput("\"climate   change\"");

        self::assertTrue($terms->isPhrase);
        self::assertSame(['climate change'], $terms->terms);
    }

    public function testAnUnquotedQueryIsNotAPhrase(): void
    {
        $terms = SearchTerms::fromInput('climate change');

        self::assertFalse($terms->isPhrase);
        self::assertSame(['climate', 'change'], $terms->terms);
    }

    public function testAPhraseWinsOverATrailingSpace(): void
    {
        // A wrapped query with a trailing space carries both signals; the exact
        // phrase is the stronger intent, so whole-word mode yields to it.
        $terms = SearchTerms::fromInput('"climate change" ');

        self::assertTrue($terms->isPhrase);
        self::assertFalse($terms->isWholeWord);
        self::assertSame(['climate change'], $terms->terms);
    }

    public function testInnerDoubleQuotesInAPhraseAreDroppedAsBoundaries(): void
    {
        // A stray quote inside the phrase would reopen a phrase; it becomes a
        // boundary and the words on either side stay in the one phrase.
        $terms = SearchTerms::fromInput('"a "b" c"');

        self::assertTrue($terms->isPhrase);
        self::assertSame(['a b c'], $terms->terms);
    }

    public function testAWrappedButEmptyPhraseFallsBackToNormalParsing(): void
    {
        // Nothing but quotes and whitespace between the wrapping quotes: there
        // is no phrase to match, so the input parses as ordinary quote text
        // rather than becoming an empty phrase search.
        $terms = SearchTerms::fromInput('" "" "');

        self::assertFalse($terms->isPhrase);
    }

    public function testASingleWordCanBeAPhrase(): void
    {
        $terms = SearchTerms::fromInput('"punk"');

        self::assertTrue($terms->isPhrase);
        self::assertSame(['punk'], $terms->terms);
    }

    public function testAnOpeningQuoteWithoutAClosingOneIsNotAPhrase(): void
    {
        // Only a query that BOTH opens and closes with a quote is a phrase; a
        // dangling opening quote reads as ordinary text, quote and all.
        $terms = SearchTerms::fromInput('"climate');

        self::assertFalse($terms->isPhrase);
        self::assertSame(['"climate'], $terms->terms);
    }

    public function testAClosingQuoteWithoutAnOpeningOneIsNotAPhrase(): void
    {
        $terms = SearchTerms::fromInput('climate"');

        self::assertFalse($terms->isPhrase);
        self::assertSame(['climate"'], $terms->terms);
    }

    public function testAMultibytePhraseKeepsEveryCharacter(): void
    {
        // The wrapping quotes are stripped by character position, not byte
        // position: a multibyte phrase must come back whole, not truncated.
        $terms = SearchTerms::fromInput('"café crème"');

        self::assertTrue($terms->isPhrase);
        self::assertSame(['café crème'], $terms->terms);
    }

    public function testFromTermAndModeRebuildsAPhrasePhrase(): void
    {
        $terms = SearchTerms::fromTermAndMode('climate change', SearchMode::Phrase);

        self::assertTrue($terms->isPhrase);
        self::assertFalse($terms->isWholeWord);
        self::assertSame(['climate change'], $terms->terms);
    }

    public function testFromTermAndModeRebuildsAWholeWordSearch(): void
    {
        $terms = SearchTerms::fromTermAndMode('climate change', SearchMode::WholeWord);

        self::assertTrue($terms->isWholeWord);
        self::assertFalse($terms->isPhrase);
        self::assertSame(['climate', 'change'], $terms->terms);
    }
}
