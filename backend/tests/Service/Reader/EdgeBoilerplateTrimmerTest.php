<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\EdgeBoilerplateTrimmer;
use PHPUnit\Framework\TestCase;

final class EdgeBoilerplateTrimmerTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private const string LONG_PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private EdgeBoilerplateTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new EdgeBoilerplateTrimmer(new BoilerplateVerdict());
    }

    public function testKeepsEverythingWhenThereIsNoSubstantialParagraph(): void
    {
        // No block clears the prose threshold, so the edge is undefined and the
        // trimmer strikes nothing — a short list of links stays intact.
        $html = '<div><ul class="related"><li><a href="/a">A</a></li>'
            . '<li><a href="/b">B</a></li><li><a href="/c">C</a></li></ul></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testKeepsABoilerplateBlockThatSitsInTheArticleMiddle(): void
    {
        // A related-links block wedged between two long paragraphs is in the
        // middle, not an edge, so it is never eligible for removal.
        $middle = '<div class="related"><h3>Related posts</h3>'
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p>' . $middle . '<p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testRemovesATrailingRelatedGridWithFingerprintAndLinkShape(): void
    {
        // Trailing edge, two structural signals: the "related" class fingerprint
        // and a link-list shape (mostly anchors, little prose). Three leading
        // substantial paragraphs put it right after the last one, in the
        // trailing edge.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        $result = $this->trimmed($html);

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

        self::assertStringNotContainsString('newsletter', $this->trimmed($html));
    }

    public function testRemovesALeadingCommentPromptWithFingerprintAndPhrase(): void
    {
        // Leading edge, one structural signal (the "comment-respond" fingerprint)
        // corroborated by a German heading phrase. It sits before the first
        // substantial paragraph, so it is in the leading edge.
        $prompt = '<div class="comment-respond"><h3>Schreibe einen Kommentar</h3>'
            . '<p>Deine Meinung.</p></div>';
        $html = '<div>' . $prompt . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>'
            . self::PROSE . '</p></div>';

        self::assertStringNotContainsString('comment-respond', $this->trimmed($html));
    }

    public function testKeepsABlockWithOnlyOneStructuralSignal(): void
    {
        // A single "related" fingerprint with no link-list shape and no phrase is
        // not enough under the conservative rule: the block stays, even though it
        // genuinely sits in the trailing edge, right after the last of three
        // leading substantial paragraphs.
        $block = '<div class="related"><p>Kurzer Hinweis.</p></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('Read more', $this->trimmed($html));
    }

    public function testKeepsABlockWithACorroboratingHeadingButNoStructuralSignal(): void
    {
        // "Related posts" matches the phrase list via the heading, but the block
        // carries no fingerprint class, no link-list shape and no form/email —
        // zero structural signals. A phrase alone never removes a block, even
        // when it sits in a real trailing edge, right after three leading
        // substantial paragraphs.
        $block = '<div><h3>Related posts</h3><p>x</p></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('Related posts', $this->trimmed($html));
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

        self::assertStringNotContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testDescendsThroughAWrapperThatHoldsAnHtmlCommentBesideTheSoleChild(): void
    {
        // A page shell leaves ESI comments next to the article container. A
        // comment is not content: the wrapper still has one sole element child,
        // so the trimmer descends into it and reaches the trailing grid (#779).
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div><!--/esi/footer--><!--/esi/player--></div>';

        self::assertStringNotContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testLooseTextBesideTheSoleChildStopsTheDescent(): void
    {
        // Real text next to the only element child is content of the wrapper
        // itself, so the wrapper is the root and its one block, being long
        // enough, is the anchor: no edge, the grid inside survives.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>Ein loser Satz neben dem Container.</div>';

        self::assertStringContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testDoesNotDescendIntoANonContainerSoleWrapper(): void
    {
        // The sole top-level element is a <span>, not one of the recognised
        // container tags (div/article/section/main), so the trimmer must not
        // descend into it. Treated as a single opaque block, the article's
        // one block is itself substantial (its combined text clears the
        // threshold), so it is the boundary anchor and has no edge region —
        // the grid inside survives even though it looks exactly like the
        // removable pattern from
        // testRemovesATrailingRelatedGridWithFingerprintAndLinkShape.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<span><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</span>';

        self::assertStringContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testNeverExtendsTheLeadingEdgeBeyondItsComputedBound(): void
    {
        // The first substantial paragraph sits at index 1, so the leading
        // edge is exactly [0]: index 1 itself — a block that happens to
        // carry two structural signals (fingerprint + email input) as well as
        // enough prose to count as substantial — is the boundary anchor, not
        // an edge candidate, and must survive untouched.
        $comboAnchor = '<div class="related"><input type="email">' . self::PROSE . self::PROSE . '</div>';
        $html = '<div><p>Short lead.</p>' . $comboAnchor . '<p>Filler.</p><p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testNeverShrinksTheTrailingEdgeBelowItsComputedBound(): void
    {
        // Six leading substantial paragraphs put trailingStart right after
        // the last one, so the trailing edge is exactly the last two
        // indexes. A boilerplate block with two structural signals sits at
        // the very last index and must be reachable and removed — nothing
        // may narrow that range and skip it.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>Filler.</p>' . $boilerplate . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testLeadingBoundUsesTheFirstSubstantialIndexNotTheSecond(): void
    {
        // The FIRST substantial block is at index 1 (a combo block: long
        // enough to count and structurally boilerplate) and a second
        // substantial paragraph sits later at index 3. The correct leading
        // edge is [0] only; using the second substantial index instead of
        // the first would widen it to [0,1] and wrongly catch the combo
        // block at index 1.
        $comboAnchor = '<div class="related"><input type="email">' . self::PROSE . self::PROSE . '</div>';
        $html = '<div><p>Filler.</p>' . $comboAnchor . '<p>Filler.</p><p>' . self::PROSE . '</p>'
            . '<p>Filler.</p><p>Filler.</p><p>Filler.</p><p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testTrailingBoundStaysAtSubstantialIndexPlusOneNotMinusOne(): void
    {
        // A substantial paragraph opens the article (index 0), so the
        // leading edge is empty and cannot reach the boilerplate. Seven
        // filler blocks and a closing substantial paragraph (index 8) put
        // the last substantial index at 8, so trailingStart correctly
        // computes to 9, past the last real index — the article ends on
        // real content and has no trailing edge at all. A boilerplate block
        // at index 7, immediately before that closing paragraph, sits in
        // that undefined region and must be left alone. Were trailingStart
        // computed as substantialIndex - 1 instead of + 1, it would land on
        // 7 and wrongly pull the boilerplate into a trailing edge.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p>'
            . '<p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p>'
            . $boilerplate . '<p>' . self::PROSE . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkListRequiresAtLeastThreeLinksNotJustOverTwo(): void
    {
        // Exactly three links, mostly-link text (ratio 1.0) — right at the
        // MIN_LINKS_FOR_LIST boundary. Combined with the fingerprint, that
        // is two structural signals, so this trailing block must be removed.
        $grid = '<div class="related"><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkTextLengthTrimsEachLinksOwnPadding(): void
    {
        // Each link's text is padded (" A ", " B ", " C "); collapsed per
        // link, the numerator is 3 characters against a collapsed block
        // denominator of 11 ("Hello A B C"), giving ratio 3/11 ≈ 0.27 — below
        // the 0.6 threshold, so with only the fingerprint left this trailing
        // block stays. Without per-link collapsing, the padding would inflate
        // the numerator to 9 and wrongly push the ratio to ≈0.82.
        $block = '<div class="related">Hello<a href="/a"> A </a><a href="/b"> B </a><a href="/c"> C </a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testFormOrEmailChecksTheInputTypeIsActuallyEmail(): void
    {
        // An <input> with no wrapping <form> and a type other than "email".
        // Only an email-typed input should count as a signal here — with
        // just the fingerprint left, this trailing block stays.
        $block = '<div class="related"><input type="text"></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
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

        self::assertStringNotContainsString('comment-respond', $this->trimmed($html));
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

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testKeepsEveryParagraphWhenNothingIsBoilerplate(): void
    {
        // A realistic multi-paragraph article with no boilerplate anywhere: no
        // block is ever removed, so every paragraph of real prose survives the
        // trim untouched.
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>';

        self::assertSame(4, substr_count($this->trimmed($html), self::PROSE));
    }

    public function testRemovesAStandaloneLeadingAdvertisementLabel(): void
    {
        $body = '<div><p><span>- Advertisement -</span></p>'
            . '<p>' . self::LONG_PROSE . '</p></div>';

        $result = $this->trimmed($body);

        self::assertStringNotContainsString('Advertisement', $result);
        self::assertStringContainsString(self::LONG_PROSE, $result);
    }

    public function testRemovesAGermanAnzeigeLabel(): void
    {
        $body = '<div><p>Anzeige</p><p>' . self::LONG_PROSE . '</p></div>';

        self::assertStringNotContainsString('Anzeige', $this->trimmed($body));
    }

    public function testKeepsAParagraphThatMerelyContainsTheWordAdvertisement(): void
    {
        $body = '<div><p>The advertisement industry changed in 2026 for many reasons here.</p>'
            . '<p>' . self::LONG_PROSE . '</p></div>';

        self::assertStringContainsString('advertisement industry', $this->trimmed($body));
    }

    public function testRemovesLeadingBoilerplateOnATwoBlockWrapper(): void
    {
        // With the cap gone, a 2-block wrapper's leading link-list + phrase is
        // reachable (floor(0.25 * 2) was 0 before).
        $related = '<div class="related"><h3>Related posts</h3>'
            . '<a href="https://x.test/a">A</a><a href="https://x.test/b">B</a>'
            . '<a href="https://x.test/c">C</a></div>';
        $body = '<div>' . $related . '<p>' . self::LONG_PROSE . '</p></div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($body));
    }

    /**
     * Parses the fragment, runs the in-place trim, and serialises the shared
     * document — mirroring the parse-once/serialise-once window ReaderBodyCleaner
     * owns in the pipeline.
     */
    public function testRemovesATrailingTeaserCardListWithoutFingerprintOrPhrase(): void
    {
        // A publisher's "more on the topic" carousel: no fingerprint class, the
        // section title in a <span> so no heading phrase can corroborate, but
        // three links that each wrap a picture and a title. Link-dominated
        // text plus picture cards are two structural signals (#779).
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . self::teaserCard('/a', 'Erster Beitrag')
            . self::teaserCard('/b', 'Zweiter Beitrag') . self::teaserCard('/c', 'Dritter Beitrag')
            . '</section></div>';

        self::assertStringNotContainsString('swiper', $this->trimmed($html));
    }

    public function testKeepsATrailingLinkListWhoseLinksCarryNoPicture(): void
    {
        // Three text-only links, no fingerprint, no phrase: the link-list shape
        // is the only signal, so a closing list of sources stays.
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<ul class="sources"><li><a href="/a">Alpha</a></li><li><a href="/b">Beta</a></li>'
            . '<li><a href="/c">Gamma</a></li></ul></div>';

        self::assertStringContainsString('sources', $this->trimmed($html));
    }

    public function testTwoPictureCardsBesideATextLinkAreNotACardList(): void
    {
        // Three links keep the link-list signal, but only two of them wrap a
        // picture — one short of a card list, so the block stays.
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . self::teaserCard('/a', 'Erster Beitrag')
            . self::teaserCard('/b', 'Zweiter Beitrag') . '<a href="/c">Dritter Beitrag</a></section></div>';

        self::assertStringContainsString('swiper', $this->trimmed($html));
    }

    public function testAShowAllLinkBesideThreeCardsDoesNotCancelACard(): void
    {
        // Carousels end in a text-only "show all" link. It is not a card, but
        // it must not count against the three cards either: the block is still
        // a card list and goes.
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . self::teaserCard('/a', 'Erster Beitrag')
            . self::teaserCard('/b', 'Zweiter Beitrag') . self::teaserCard('/c', 'Dritter Beitrag')
            . '<a href="/alle">Alle anzeigen</a></section></div>';

        self::assertStringNotContainsString('swiper', $this->trimmed($html));
    }

    public function testPictureCardsAloneDoNotRemoveABlockThatIsNotLinkDominated(): void
    {
        // A closing gallery: three linked pictures with a caption of prose
        // outside the links. The cards are one signal, but the text is not
        // link-dominated, so the gallery stays.
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<div class="gallery"><a href="/a"><img src="/a.jpg" alt=""></a>'
            . '<a href="/b"><img src="/b.jpg" alt=""></a><a href="/c"><img src="/c.jpg" alt=""></a>'
            . '<p>Drei Aufnahmen vom Abend, fotografiert von der Autorin.</p></div></div>';

        self::assertStringContainsString('gallery', $this->trimmed($html));
    }

    public function testALinkDominatedBlockNeverAnchorsAnEdgeHoweverLongItRuns(): void
    {
        // Seven teaser cards run past the 200-character prose bar, but their
        // text is almost all link text: a list, not prose. It must not count
        // as the article's last paragraph and shield itself from the trailing
        // edge (#779).
        $cards = '';
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $slug) {
            $cards .= self::teaserCard('/' . $slug, 'Ein Teaser mit einer langen Überschrift, wie Verlage sie setzen');
        }
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . $cards . '</section></div>';

        self::assertStringNotContainsString('swiper', $this->trimmed($html));
    }

    public function testLinkRatioMeasuresCollapsedTextSoIndentationDoesNotDiluteIt(): void
    {
        // Pretty-printed markup leaves a run of indentation between each link.
        // Raw, that whitespace is 33 of 47 characters and drags the ratio to
        // 0.3; collapsed, the links are 14 of 16 characters. Fingerprint plus
        // link list: this trailing block must be removed.
        $indent = "\n          ";
        $block = '<div class="related">' . $indent . '<a href="/a">Alpha</a>' . $indent
            . '<a href="/b">Beta</a>' . $indent . '<a href="/c">Gamma</a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testSubstantialityMeasuresCollapsedTextSoIndentationDoesNotInflateIt(): void
    {
        // 190 letters split by a 60-character whitespace run: raw, the block
        // reads 250 characters and would anchor the trailing edge; collapsed,
        // it is 191 and stays below the bar. Fingerprint plus email input:
        // this trailing block must be removed.
        $target = '<div class="related"><input type="email">' . str_repeat('x', 100)
            . str_repeat("\n", 60) . str_repeat('x', 90) . '</div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $target . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testThreeEmptyLinksAreNotLinkDominated(): void
    {
        // Three links with no text at all give the block no text to measure.
        // That is not link domination — with only the fingerprint left, this
        // trailing block stays.
        $block = '<div class="related"><a href="/a"></a><a href="/b"></a><a href="/c"></a></div>';
        $html = '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    private static function teaserCard(string $href, string $title): string
    {
        return '<a href="' . $href . '"><img src="' . $href . '.jpg" alt=""><h3>' . $title . '</h3></a>';
    }

    private function trimmed(string $bodyHtml): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);

        $this->trimmer->trimIn($document);

        return $document->saveHtml();
    }
}
