<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\EdgeBoilerplateTrimmer;
use PHPUnit\Framework\TestCase;

final class EdgeBoilerplateTrimmerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private EdgeBoilerplateTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new EdgeBoilerplateTrimmer();
    }

    public function testReturnsUnparsableInputUnchanged(): void
    {
        self::assertSame('', $this->trimmer->trim(''));
    }

    public function testKeepsEverythingWhenThereIsNoSubstantialParagraph(): void
    {
        // No block clears the prose threshold, so the edge is undefined and the
        // trimmer strikes nothing — a short list of links stays intact.
        $html = '<div><ul class="related"><li><a href="/a">A</a></li>'
            . '<li><a href="/b">B</a></li><li><a href="/c">C</a></li></ul></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testKeepsABoilerplateBlockThatSitsInTheArticleMiddle(): void
    {
        // A related-links block wedged between two long paragraphs is in the
        // middle, not an edge, so it is never eligible for removal.
        $middle = '<div class="related"><h3>Related posts</h3>'
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p>' . $middle . '<p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testRemovesATrailingRelatedGridWithFingerprintAndLinkShape(): void
    {
        // Trailing edge, two structural signals: the "related" class fingerprint
        // and a link-list shape (mostly anchors, little prose). Three leading
        // substantial paragraphs push the block count to 4 so the trailing cap
        // (floor(0.25*4)=1) actually admits the grid as an edge block.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        $result = $this->trimmer->trim($html);

        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString(self::PROSE, $result);
    }

    public function testRemovesATrailingNewsletterFormWithFingerprintAndForm(): void
    {
        // Fingerprint ("newsletter") plus a form/email signal, kept short so it
        // stays non-substantial and lands in the trailing edge alongside three
        // substantial paragraphs.
        $form = '<div class="newsletter"><form><input type="email"><button>Sign up</button></form></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $form . '</div>';

        self::assertStringNotContainsString('newsletter', $this->trimmer->trim($html));
    }

    public function testRemovesALeadingCommentPromptWithFingerprintAndPhrase(): void
    {
        // Leading edge, one structural signal (the "comment-respond" fingerprint)
        // corroborated by a German heading phrase. Three trailing substantial
        // paragraphs push the block count to 4 so the leading cap admits it.
        $prompt = '<div class="comment-respond"><h3>Schreibe einen Kommentar</h3>'
            . '<p>Deine Meinung.</p></div>';
        $html = '<div>' . $prompt . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>'
            . self::PROSE . '</p></div>';

        self::assertStringNotContainsString('comment-respond', $this->trimmer->trim($html));
    }

    public function testKeepsABlockWithOnlyOneStructuralSignal(): void
    {
        // A single "related" fingerprint with no link-list shape and no phrase is
        // not enough under the conservative rule: the block stays, even though it
        // genuinely sits in the trailing edge (three leading substantial
        // paragraphs make the block count 4, so the cap admits it).
        $block = '<div class="related"><p>Kurzer Hinweis.</p></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testKeepsABlockWithOnlyAPhraseAndNoStructuralSignal(): void
    {
        // A heading phrase alone never triggers a removal — phrases only
        // corroborate. A short trailing note that merely says "Read more" but
        // carries no fingerprint, link list or form is kept, even though it
        // genuinely sits in the trailing edge.
        $note = '<p>Read more about our work in the archive.</p>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $note . '</div>';

        self::assertStringContainsString('Read more', $this->trimmer->trim($html));
    }

    public function testKeepsABlockWithACorroboratingHeadingButNoStructuralSignal(): void
    {
        // "Related posts" matches the phrase list via the heading, but the block
        // carries no fingerprint class, no link-list shape and no form/email —
        // zero structural signals. A phrase alone never removes a block, even
        // when it sits in a real trailing edge (three leading substantial
        // paragraphs make the block count 4, so the cap admits it).
        $block = '<div><h3>Related posts</h3><p>x</p></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('Related posts', $this->trimmer->trim($html));
    }

    public function testDescendsThroughAWrapperFollowedByOnlyWhitespaceText(): void
    {
        // A whitespace-only text node next to the sole wrapper div must not
        // block the content-root descent: trim() reduces it to '', so the
        // wrapper is still recognised as the sole element child and the
        // trimmer descends into it and reaches the trailing grid.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>' . "   \n  ";

        self::assertStringNotContainsString('jp-relatedposts', $this->trimmer->trim($html));
    }

    public function testDoesNotDescendIntoANonContainerSoleWrapper(): void
    {
        // The sole top-level element is a <span>, not one of the recognised
        // container tags (div/article/section/main), so the trimmer must not
        // descend into it. Treated as a single opaque block, the article has
        // no edge region (count=1, cap=0), so the grid inside survives even
        // though it looks exactly like the removable pattern from
        // testRemovesATrailingRelatedGridWithFingerprintAndLinkShape.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<span><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</span>';

        self::assertStringContainsString('jp-relatedposts', $this->trimmer->trim($html));
    }

    public function testNeverExtendsTheLeadingEdgeBeyondItsComputedBound(): void
    {
        // Four blocks, leading cap = floor(0.25*4) = 1. The first substantial
        // paragraph sits at index 1, so the leading edge is exactly [0]:
        // index 1 itself — a block that happens to carry two structural
        // signals (fingerprint + link list) as well as enough prose to count
        // as substantial — is the boundary anchor, not an edge candidate, and
        // must survive untouched.
        $comboAnchor = '<div class="related">' . self::PROSE
            . '<a href="/a">' . self::PROSE . '</a><a href="/b">' . self::PROSE . '</a>'
            . '<a href="/c">' . self::PROSE . '</a></div>';
        $html = '<div><p>Short lead.</p>' . $comboAnchor . '<p>Filler.</p><p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testNeverShrinksTheTrailingEdgeBelowItsComputedBound(): void
    {
        // Eight blocks, trailing cap = floor(0.25*8) = 2, so the trailing
        // edge is exactly the last two indexes. A boilerplate block with two
        // structural signals sits at the very last index and must be
        // reachable and removed — nothing may narrow that range and skip it.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>Filler.</p>' . $boilerplate . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testCapUsesFloorNotCeilOrRound(): void
    {
        // Six blocks: 0.25*6 = 1.5, which floor() rounds to 1 but ceil() and
        // round() both round to 2. A boilerplate block at index 4 falls
        // outside the floor-based trailing edge (which is only index 5) and
        // must survive — a wider cap would wrongly pull it in and remove it.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>Filler.</p>' . $boilerplate . '<p>Tail.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLeadingBoundUsesTheFirstSubstantialIndexNotTheSecond(): void
    {
        // Eight blocks: the first substantial paragraph is at index 3, the
        // second at index... no — here the FIRST substantial block is at
        // index 1 (a combo block: long enough to count and structurally
        // boilerplate) and a second substantial paragraph sits later at
        // index 3. The leading cap is floor(0.25*8)=2, so the correct
        // leading edge is [0] only (min(1,2)=1 -> range(0,0)); using the
        // second substantial index instead of the first would widen it to
        // [0,1] and wrongly catch the combo block at index 1.
        $comboAnchor = '<div class="related">' . self::PROSE
            . '<a href="/a">' . self::PROSE . '</a><a href="/b">' . self::PROSE . '</a>'
            . '<a href="/c">' . self::PROSE . '</a></div>';
        $html = '<div><p>Filler.</p>' . $comboAnchor . '<p>Filler.</p><p>' . self::PROSE . '</p>'
            . '<p>Filler.</p><p>Filler.</p><p>Filler.</p><p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testTrailingBoundExcludesTheLastSubstantialIndexItself(): void
    {
        // Eight blocks: six leading substantial paragraphs (indexes 0-5),
        // then a combo block at index 6 that is itself long enough to count
        // as substantial while also carrying two structural signals, then a
        // filler at index 7. The last substantial index is 6, so the
        // trailing edge must start at 7 — the combo block at 6 is the
        // boundary anchor itself and must never be pulled into the trailing
        // edge alongside it.
        $comboAnchor = '<div class="related"><input type="email">' . self::PROSE
            . self::PROSE . '</div>';
        $html = '<div>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $comboAnchor . '<p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testTrailingBoundStaysAtSubstantialIndexPlusOneNotMinusOne(): void
    {
        // Ten blocks, all but the last are non-substantial; the last (index
        // 9) is a plain substantial paragraph, so trailingStart correctly
        // computes to 10, past the last real index — the whole article ends
        // on real content and has no trailing edge at all. A boilerplate
        // block at index 8, immediately before that closing paragraph,
        // sits in that undefined region and must be left alone.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div>'
            . '<p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p>'
            . $boilerplate . '<p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testSubstantialityIgnoresPaddingWhitespaceAroundTheText(): void
    {
        // The trailing block's real content is a 3-char link list ("ABC"),
        // padded with 250 leading spaces so its raw (untrimmed) length would
        // clear the 200-char substantial threshold. Trimmed, it is nowhere
        // near substantial, so it must stay eligible for removal alongside
        // the fingerprint + link-list signals it carries.
        $padded = '<div class="related">' . str_repeat(' ', 250)
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $padded . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testSubstantialityCountsCharactersNotBytes(): void
    {
        // The trailing block's trimmed text is "aaa...a" (185 chars) plus 10
        // "ä" characters — 195 characters, so it stays under the 200-char
        // substantial threshold. In UTF-8 those 10 "ä" cost 2 bytes each, so
        // a byte-counting measure would read 205 and wrongly call it
        // substantial. It carries a fingerprint and an email input, so it
        // must stay removable.
        $target = '<div class="related">' . str_repeat('a', 185) . str_repeat('ä', 10)
            . '<input type="email"></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $target . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testSubstantialityThresholdIsInclusiveAtExactly200Characters(): void
    {
        // The leading block's text is exactly 200 characters — at the
        // threshold, not past it — so it counts as substantial and is the
        // leading anchor itself (leadingEnd=0, excluded from the edge). It
        // also carries two structural signals (fingerprint + email input),
        // so if the threshold were exclusive it would wrongly fall into the
        // leading edge and get removed.
        $target = '<div class="related">' . str_repeat('x', 200) . '<input type="email"></div>';
        $html = '<div>' . $target . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testSubstantialIndexesAreNotCollapsedToJustTheFirst(): void
    {
        // Eight blocks: a plain substantial paragraph at index 0, five short
        // fillers, then at index 6 a combo block — long enough to count as
        // substantial in its own right while also carrying two structural
        // signals — and a filler at index 7. With both substantial indexes
        // (0 and 6) tracked, the trailing edge starts at 7 and the combo
        // block at 6 is the boundary anchor, left alone. Collapsing the
        // substantial list down to just its first entry (index 0) would
        // recompute the trailing edge from a stale, too-early anchor and
        // wrongly pull the combo block at index 6 into it.
        $comboAnchor = '<div class="related"><input type="email">' . str_repeat('x', 205) . '</div>';
        $html = '<div><p>' . self::PROSE . '</p><p>Filler.</p><p>Filler.</p><p>Filler.</p>'
            . '<p>Filler.</p><p>Filler.</p>' . $comboAnchor . '<p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLinkListRequiresAtLeastThreeLinksNotJustOverTwo(): void
    {
        // Exactly three links, mostly-link text (ratio 1.0) — right at the
        // MIN_LINKS_FOR_LIST boundary. Combined with the fingerprint, that
        // is two structural signals, so this trailing block must be removed.
        $grid = '<div class="related"><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testFewerThanThreeLinksIsNeverALinkListRegardlessOfRatio(): void
    {
        // Only two links, whose text is the block's entire content (ratio
        // 1.0). MIN_LINKS_FOR_LIST must reject this outright before the
        // ratio is even considered — with only the fingerprint left as a
        // signal, this trailing block stays.
        $pair = '<div class="related"><a href="/a">A</a><a href="/b">B</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $pair . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLinkListRatioIgnoresPaddingWhitespaceInTheDenominator(): void
    {
        // 300 leading spaces followed by three one-letter links: trimmed,
        // the block's text is just "ABC" and the links account for all of
        // it (ratio 1.0). Untrimmed, the denominator balloons to 303 and the
        // ratio collapses to near zero. Combined with the fingerprint, the
        // correct (trimmed) ratio gives two signals and this trailing block
        // must be removed.
        $grid = '<div class="related">' . str_repeat(' ', 300)
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLinkListRatioBoundaryOfExactlyPointSixCountsAsAList(): void
    {
        // Denominator "ää" + "ABC" = 5 characters; numerator (the three
        // one-letter links) = 3 characters. 3/5 = 0.6 exactly, at the
        // LINK_TEXT_RATIO boundary, which is inclusive ("at least this
        // ratio"). Combined with the fingerprint, that is two signals, so
        // this trailing block must be removed.
        $grid = '<div class="related">ää<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLinkTextLengthCountsCharactersNotBytes(): void
    {
        // Denominator: "Hello" (5 chars) + three one-char "ä" links = 8
        // characters. Numerator: the three "ä" links = 3 characters.
        // 3/8 = 0.375, below the 0.6 ratio, so this is NOT a link list —
        // with only the fingerprint left, this trailing block stays. A
        // byte-counting numerator would read 6 (each "ä" is 2 bytes) and
        // wrongly cross the ratio threshold (6/8 = 0.75).
        $block = '<div class="related">Hello<a href="/a">ä</a><a href="/b">ä</a><a href="/c">ä</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLinkTextLengthTrimsEachLinksOwnPadding(): void
    {
        // Each link's text is padded (" A ", " B ", " C "); trimmed per
        // link, the numerator is 3 characters against a trimmed block
        // denominator of 7 ("A  B  C"), giving ratio 3/7 ≈ 0.43 — below the
        // 0.6 threshold, so with only the fingerprint left this trailing
        // block stays. Without per-link trimming, the padding would inflate
        // the numerator to 9 and wrongly push the ratio to ≈1.29.
        $block = '<div class="related"><a href="/a"> A </a><a href="/b"> B </a><a href="/c"> C </a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testLinkListRatioIsAQuotientNotAProduct(): void
    {
        // Three short links ("a","b","c") sit inside a long, mostly-prose
        // block, so the true ratio (3 link characters over ~230 block
        // characters) is far below 0.6 — with only the fingerprint left,
        // this trailing block stays. Multiplying the two lengths instead of
        // dividing them would yield a huge number that clears any ratio
        // threshold and wrongly remove it.
        $block = '<div class="related">' . self::PROSE
            . '<a href="/a">a</a><a href="/b">b</a><a href="/c">c</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testFormPresenceAloneCountsAsAStructuralSignalEvenWithoutEmail(): void
    {
        // A <form> with a plain text input, no email field at all. The form
        // check must short-circuit on the <form> element itself — combined
        // with the fingerprint, that is two signals, so this trailing block
        // must be removed.
        $block = '<div class="related"><form><input type="text"></form></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testFormOrEmailChecksTheInputTypeIsActuallyEmail(): void
    {
        // An <input> with no wrapping <form> and a type other than "email".
        // Only an email-typed input should count as a signal here — with
        // just the fingerprint left, this trailing block stays.
        $block = '<div class="related"><input type="text"></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testCorroboratingPhraseMatchIsCaseInsensitiveAcrossUmlauts(): void
    {
        // The German fragment "ähnliche beiträge" only appears title-cased
        // in the heading ("Ähnliche Beiträge"). A byte-wise lowercasing
        // leaves the leading "Ä" untouched (it is outside the ASCII a-z
        // range it knows how to fold), so the match only succeeds with a
        // proper multibyte lowercase. One structural signal (the
        // "comment-respond" fingerprint) plus this corroborating heading is
        // enough to remove this leading block.
        $prompt = '<div class="comment-respond"><h3>Ähnliche Beiträge</h3><p>Text.</p></div>';
        $html = '<div>' . $prompt . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>'
            . self::PROSE . '</p></div>';

        self::assertStringNotContainsString('comment-respond', $this->trimmer->trim($html));
    }

    public function testCorroboratingPhraseRequiresAnActualMatchNotAnyHeading(): void
    {
        // A heading is present but its text matches none of the phrase
        // fragments. One structural signal (the "related" fingerprint)
        // without a real corroborating phrase is not enough under the
        // conservative rule, so this trailing block stays.
        $block = '<div class="related"><h3>Our Team History</h3><p>Text.</p></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmer->trim($html));
    }

    public function testReturnsTheOriginalStringByteIdenticalWhenNothingIsRemoved(): void
    {
        // A realistic multi-paragraph article with no boilerplate anywhere: no
        // block is ever removed, so trim() must hand back the exact original
        // string instead of a DOM-normalised re-serialisation via saveHtml().
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>';

        self::assertSame($html, $this->trimmer->trim($html));
    }
}
