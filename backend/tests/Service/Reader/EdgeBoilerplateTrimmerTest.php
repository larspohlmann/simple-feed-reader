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
}
