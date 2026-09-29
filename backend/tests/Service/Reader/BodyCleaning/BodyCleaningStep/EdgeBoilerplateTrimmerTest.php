<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\EdgeBoilerplateTrimmer;
use App\Service\Reader\BoilerplateVerdict;
use App\Service\Reader\LinkListDetector;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use App\Tests\Support\ProseParagraphs;
use PHPUnit\Framework\TestCase;

final class EdgeBoilerplateTrimmerTest extends TestCase
{
    use ParsesHtml;

    private const string LONG_PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private EdgeBoilerplateTrimmer $trimmer;

    protected function setUp(): void
    {
        $this->trimmer = new EdgeBoilerplateTrimmer(
            new BoilerplateVerdict(new LinkListDetector()),
            new LinkListDetector(),
        );
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
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p>' . $middle . '<p>' . ProseParagraphs::SUBSTANTIAL
            . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testRemovesATrailingRelatedGridWithFingerprintAndLinkShape(): void
    {
        // A "related" fingerprint plus a link-list shape, right after the last of three substantial paragraphs.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</div>';

        $result = $this->trimmed($html);

        self::assertStringNotContainsString('jp-relatedposts', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testRemovesATrailingNewsletterFormWithFingerprintAndForm(): void
    {
        // Fingerprint ("newsletter") plus a form/email signal, kept short so it
        // stays non-substantial and lands in the trailing edge alongside three
        // substantial paragraphs.
        $form = '<div class="newsletter"><form><input type="email"><button>Sign up</button></form></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
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
        $html = '<div>' . $prompt . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL
            . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertStringNotContainsString('comment-respond', $this->trimmed($html));
    }

    public function testKeepsABlockWithOnlyOneStructuralSignal(): void
    {
        // A lone "related" fingerprint, with no link list and no phrase, is one signal: the trailing block stays.
        $block = '<div class="related"><p>Kurzer Hinweis.</p></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testKeepsABlockWithOnlyAPhraseAndNoStructuralSignal(): void
    {
        // A phrase only corroborates: a trailing "Read more" note with no fingerprint, link list or form stays.
        $note = '<p>Read more about our work in the archive.</p>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $note . '</div>';

        self::assertStringContainsString('Read more', $this->trimmed($html));
    }

    public function testKeepsABlockWithACorroboratingHeadingButNoStructuralSignal(): void
    {
        // "Related posts" matches a phrase, but with no fingerprint, link list or form the block has no
        // structural signal, so it stays.
        $block = '<div><h3>Related posts</h3><p>x</p></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('Related posts', $this->trimmed($html));
    }

    public function testDescendsThroughAWrapperFollowedByOnlyWhitespaceText(): void
    {
        // Whitespace-only text beside the sole wrapper is not content, so the trimmer still descends into it and
        // reaches the trailing grid.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</div>' . "   \n  ";

        self::assertStringNotContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testDescendsThroughAWrapperThatHoldsAnHtmlCommentBesideTheSoleChild(): void
    {
        // ESI comments beside the article container are not content: the wrapper keeps one sole element child,
        // so the trimmer descends into it and reaches the trailing grid.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<div><div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
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
        $html = '<div><div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</div>Ein loser Satz neben dem Container.</div>';

        self::assertStringContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testDoesNotDescendIntoANonContainerSoleWrapper(): void
    {
        // A <span> is not a container tag, so the trimmer does not descend: the one opaque block is substantial
        // and anchors with no edge, so the grid inside survives.
        $grid = '<div class="jp-relatedposts"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a><a href="/d">D</a></div>';
        $html = '<span><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</span>';

        self::assertStringContainsString('jp-relatedposts', $this->trimmed($html));
    }

    public function testNeverExtendsTheLeadingEdgeBeyondItsComputedBound(): void
    {
        // The first substantial block, at index 1, is a fingerprint-plus-email combo: the leading edge is [0], and
        // the combo, as the anchor, survives.
        $comboAnchor = '<div class="related"><input type="email">' . ProseParagraphs::SUBSTANTIAL
            . ProseParagraphs::SUBSTANTIAL . '</div>';
        $html = '<div><p>Short lead.</p>' . $comboAnchor . '<p>Filler.</p><p>' . ProseParagraphs::SUBSTANTIAL
            . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testNeverShrinksTheTrailingEdgeBelowItsComputedBound(): void
    {
        // Six substantial paragraphs, a filler, then boilerplate at the last index: the trailing edge is the last
        // two blocks, and the boilerplate must be reached and removed.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<p>Filler.</p>' . $boilerplate . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testLeadingBoundUsesTheFirstSubstantialIndexNotTheSecond(): void
    {
        // The first substantial index (1, a combo block) bounds the leading edge, not the second (3): the edge is
        // [0], so the combo stays.
        $comboAnchor = '<div class="related"><input type="email">' . ProseParagraphs::SUBSTANTIAL
            . ProseParagraphs::SUBSTANTIAL . '</div>';
        $html = '<div><p>Filler.</p>' . $comboAnchor . '<p>Filler.</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<p>Filler.</p><p>Filler.</p><p>Filler.</p><p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testTrailingBoundExcludesTheLastSubstantialIndexItself(): void
    {
        // The combo block at index 6 is the last substantial one, so the trailing edge starts at 7 and the combo,
        // the anchor, stays.
        $comboAnchor = '<div class="related"><input type="email">' . ProseParagraphs::SUBSTANTIAL
            . ProseParagraphs::SUBSTANTIAL . '</div>';
        $html = '<div>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $comboAnchor . '<p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testTrailingBoundStaysAtSubstantialIndexPlusOneNotMinusOne(): void
    {
        // Substantial paragraphs at 0 and 8 with boilerplate at 7: the trailing edge starts at 9, past the end,
        // so 7 sits in the middle and stays.
        $boilerplate = '<div class="related"><a href="/a">A</a><a href="/b">B</a>'
            . '<a href="/c">C</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p><p>F.</p>'
            . $boilerplate . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testSubstantialityIgnoresPaddingWhitespaceAroundTheText(): void
    {
        // 250 padding spaces around a three-letter link list: trimmed, the block is far from substantial, so it
        // stays in the edge and goes.
        $padded = '<div class="related">' . str_repeat(' ', 250)
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $padded . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testSubstantialityCountsCharactersNotBytes(): void
    {
        // 185 "a" plus 10 "ä" are 195 characters (205 bytes), under the 200-character bar, so the block with a
        // fingerprint and an email input stays in the edge and goes.
        $target = '<div class="related">' . str_repeat('a', 185) . str_repeat('ä', 10)
            . '<input type="email"></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $target . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testSubstantialityThresholdIsInclusiveAtExactly200Characters(): void
    {
        // Exactly 200 characters is substantial, so the combo block is the leading anchor and stays.
        $target = '<div class="related">' . str_repeat('x', 200) . '<input type="email"></div>';
        $html = '<div>' . $target . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL
            . '</p><p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testSubstantialIndexesAreNotCollapsedToJustTheFirst(): void
    {
        // Substantial blocks at 0 and 6 (a combo): the trailing edge starts after the last of them, so the combo
        // is the anchor and stays.
        $comboAnchor = '<div class="related"><input type="email">' . str_repeat('x', 205) . '</div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>Filler.</p><p>Filler.</p><p>Filler.</p>'
            . '<p>Filler.</p><p>Filler.</p>' . $comboAnchor . '<p>Filler.</p></div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkListRequiresAtLeastThreeLinksNotJustOverTwo(): void
    {
        // Exactly three links, mostly-link text (ratio 1.0) — right at the
        // MIN_LINKS_FOR_LIST boundary. Combined with the fingerprint, that
        // is two structural signals, so this trailing block must be removed.
        $grid = '<div class="related"><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testFewerThanThreeLinksIsNeverALinkListRegardlessOfRatio(): void
    {
        // Two links are under MIN_LINKS_FOR_LIST whatever the ratio: only the fingerprint is left, so the block
        // stays.
        $pair = '<div class="related"><a href="/a">A</a><a href="/b">B</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $pair . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkListRatioIgnoresPaddingWhitespaceInTheDenominator(): void
    {
        // 300 padding spaces before three one-letter links: trimmed, the links are all of the text (ratio 1.0), so
        // with the fingerprint the block goes.
        $grid = '<div class="related">' . str_repeat(' ', 300)
            . '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkListRatioBoundaryOfExactlyPointSixCountsAsAList(): void
    {
        // "ää" plus three one-letter links is 3/5 = 0.6 exactly, and LINK_TEXT_RATIO is inclusive: with the
        // fingerprint, the block goes.
        $grid = '<div class="related">ää<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $grid . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkTextLengthCountsCharactersNotBytes(): void
    {
        // The three "ä" links are 3 of 8 characters (0.375), not a list, so the block stays; counted in bytes
        // they would read 6 of 8.
        $block = '<div class="related">Hello<a href="/a">ä</a><a href="/b">ä</a><a href="/c">ä</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkTextLengthTrimsEachLinksOwnPadding(): void
    {
        // Each link's padding collapses: 3 link characters of 11 (about 0.27) is not a list, so only the
        // fingerprint is left and the block stays.
        $block = '<div class="related">Hello<a href="/a"> A </a><a href="/b"> B </a><a href="/c"> C </a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testLinkListRatioIsAQuotientNotAProduct(): void
    {
        // Three one-letter links inside substantial prose are far below the ratio: only the fingerprint is left,
        // so the block stays.
        $block = '<div class="related">' . ProseParagraphs::SUBSTANTIAL
            . '<a href="/a">a</a><a href="/b">b</a><a href="/c">c</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testFormPresenceAloneCountsAsAStructuralSignalEvenWithoutEmail(): void
    {
        // A <form> is a signal without any email input: with the fingerprint, the block goes.
        $block = '<div class="related"><form><input type="text"></form></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testFormOrEmailChecksTheInputTypeIsActuallyEmail(): void
    {
        // An <input> with no wrapping <form> and a type other than "email".
        // Only an email-typed input should count as a signal here — with
        // just the fingerprint left, this trailing block stays.
        $block = '<div class="related"><input type="text"></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testCorroboratingPhraseMatchIsCaseInsensitiveAcrossUmlauts(): void
    {
        // "Ähnliche Beiträge" matches its lower-case fragment only through a multibyte lowercase; with the
        // "comment-respond" fingerprint, the leading block goes.
        $prompt = '<div class="comment-respond"><h3>Ähnliche Beiträge</h3><p>Text.</p></div>';
        $html = '<div>' . $prompt . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL
            . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertStringNotContainsString('comment-respond', $this->trimmed($html));
    }

    public function testCorroboratingPhraseRequiresAnActualMatchNotAnyHeading(): void
    {
        // A heading that matches no phrase does not corroborate: one signal, so the block stays.
        $block = '<div class="related"><h3>Our Team History</h3><p>Text.</p></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    public function testKeepsEveryParagraphWhenNothingIsBoilerplate(): void
    {
        // A realistic multi-paragraph article with no boilerplate anywhere: no
        // block is ever removed, so every paragraph of real prose survives the
        // trim untouched.
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p></div>';

        self::assertSame(4, substr_count($this->trimmed($html), ProseParagraphs::SUBSTANTIAL));
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
        // A two-block wrapper still has a leading edge: its link list plus phrase goes.
        $related = '<div class="related"><h3>Related posts</h3>'
            . '<a href="https://x.test/a">A</a><a href="https://x.test/b">B</a>'
            . '<a href="https://x.test/c">C</a></div>';
        $body = '<div>' . $related . '<p>' . self::LONG_PROSE . '</p></div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($body));
    }

    public function testRemovesATrailingTeaserCardListWithoutFingerprintOrPhrase(): void
    {
        // A carousel with no fingerprint and its title in a <span>, so no phrase: link-dominated text plus
        // picture cards are two signals.
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . self::teaserCard('/a', 'Erster Beitrag')
            . self::teaserCard('/b', 'Zweiter Beitrag') . self::teaserCard('/c', 'Dritter Beitrag')
            . '</section></div>';

        self::assertStringNotContainsString('swiper', $this->trimmed($html));
    }

    public function testKeepsATrailingLinkListWhoseLinksCarryNoPicture(): void
    {
        // Three text-only links, no fingerprint, no phrase: the link-list shape
        // is the only signal, so a closing list of sources stays.
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<ul class="sources"><li><a href="/a">Alpha</a></li><li><a href="/b">Beta</a></li>'
            . '<li><a href="/c">Gamma</a></li></ul></div>';

        self::assertStringContainsString('sources', $this->trimmed($html));
    }

    public function testTwoPictureCardsBesideATextLinkAreNotACardList(): void
    {
        // Three links keep the link-list signal, but only two of them wrap a
        // picture — one short of a card list, so the block stays.
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . self::teaserCard('/a', 'Erster Beitrag')
            . self::teaserCard('/b', 'Zweiter Beitrag') . '<a href="/c">Dritter Beitrag</a></section></div>';

        self::assertStringContainsString('swiper', $this->trimmed($html));
    }

    public function testAShowAllLinkBesideThreeCardsDoesNotCancelACard(): void
    {
        // Carousels end in a text-only "show all" link. It is not a card, but
        // it must not count against the three cards either: the block is still
        // a card list and goes.
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
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
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<div class="gallery"><a href="/a"><img src="/a.jpg" alt=""></a>'
            . '<a href="/b"><img src="/b.jpg" alt=""></a><a href="/c"><img src="/c.jpg" alt=""></a>'
            . '<p>Drei Aufnahmen vom Abend, fotografiert von der Autorin.</p></div></div>';

        self::assertStringContainsString('gallery', $this->trimmed($html));
    }

    public function testALinkDominatedBlockNeverAnchorsAnEdgeHoweverLongItRuns(): void
    {
        // Seven teaser cards run past the 200-character bar, but link-dominated text is a list, not prose: it
        // must not anchor the trailing edge and shield itself.
        $cards = '';
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $slug) {
            $cards .= self::teaserCard('/' . $slug, 'Ein Teaser mit einer langen Überschrift, wie Verlage sie setzen');
        }
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . '<section class="swiper"><span>Mehr dazu</span>' . $cards . '</section></div>';

        self::assertStringNotContainsString('swiper', $this->trimmed($html));
    }

    public function testLinkRatioMeasuresCollapsedTextSoIndentationDoesNotDiluteIt(): void
    {
        // The ratio reads collapsed text, so pretty-printed indentation between the links does not dilute it:
        // fingerprint plus link list, the block goes.
        $indent = "\n          ";
        $block = '<div class="related">' . $indent . '<a href="/a">Alpha</a>' . $indent
            . '<a href="/b">Beta</a>' . $indent . '<a href="/c">Gamma</a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testSubstantialityMeasuresCollapsedTextSoIndentationDoesNotInflateIt(): void
    {
        // 190 letters around a 60-character newline run: collapsed, the block stays under the bar, so fingerprint
        // plus email input removes it.
        $target = '<div class="related"><input type="email">' . str_repeat('x', 100)
            . str_repeat("\n", 60) . str_repeat('x', 90) . '</div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $target . '</div>';

        self::assertStringNotContainsString('class="related"', $this->trimmed($html));
    }

    public function testThreeEmptyLinksAreNotLinkDominated(): void
    {
        // Three links with no text at all give the block no text to measure.
        // That is not link domination — with only the fingerprint left, this
        // trailing block stays.
        $block = '<div class="related"><a href="/a"></a><a href="/b"></a><a href="/c"></a></div>';
        $html = '<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>' . ProseParagraphs::SUBSTANTIAL . '</p><p>'
            . ProseParagraphs::SUBSTANTIAL . '</p>'
            . $block . '</div>';

        self::assertStringContainsString('class="related"', $this->trimmed($html));
    }

    private static function teaserCard(string $href, string $title): string
    {
        return '<a href="' . $href . '"><img src="' . $href . '.jpg" alt=""><h3>' . $title . '</h3></a>';
    }

    private function trimmed(string $bodyHtml): string
    {
        $document = $this->document($bodyHtml);

        $this->trimmer->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }
}
