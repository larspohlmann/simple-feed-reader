<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\ShareIntentLinkRemover;
use PHPUnit\Framework\TestCase;

final class ShareIntentLinkRemoverTest extends TestCase
{
    private ShareIntentLinkRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new ShareIntentLinkRemover();
    }

    public function testRemovesABlueskyShareIntentCarryingThePageUrl(): void
    {
        $html = '<article><p>Body.</p>'
            . '<a href="https://bsky.app/intent/compose?text=https://canarymedia.com/x">Share</a>'
            . '</article>';

        $result = $this->cleaned($html);

        self::assertStringNotContainsString('bsky.app', $result);
        self::assertStringContainsString('Body.', $result);
    }

    public function testRemovesAMailtoShareCarryingThePageUrlInTheBody(): void
    {
        $html = '<div><a href="mailto:?subject=Poo&amp;body=https://www.nature.com/articles/x">Email</a>'
            . '<p>Body.</p></div>';

        self::assertStringNotContainsString('mailto:', $this->cleaned($html));
    }

    public function testKeepsAWhatsAppContactLinkThatCarriesNoPageUrl(): void
    {
        // POLITICO's write-to-the-hosts link: a share host, but no page URL.
        $html = '<div><p>Body.</p>'
            . '<a href="https://api.whatsapp.com/send/?phone=32491050629&amp;text=Hey+Zoya+and+crew!">Message us</a>'
            . '</div>';

        self::assertStringContainsString('api.whatsapp.com', $this->cleaned($html));
    }

    public function testKeepsAnOrdinaryOutboundLinkToAShareHostDomain(): void
    {
        // A plain link to facebook.com (not a /sharer endpoint) is not a control.
        $html = '<div><p>Body.</p><a href="https://facebook.com/politico">Our page</a></div>';

        self::assertStringContainsString('facebook.com/politico', $this->cleaned($html));
    }

    public function testRemovesTheWholeShareClusterIncludingItsLabel(): void
    {
        $html = '<div><p>Body.</p>'
            . '<div class="bar"><span>Share this article</span>'
            . '<a href="https://www.facebook.com/sharer/sharer.php?u=https://x.test/a">FB</a>'
            . '<a href="https://x.com/intent/tweet?url=https://x.test/a">X</a>'
            . '</div></div>';

        $result = $this->cleaned($html);

        self::assertStringNotContainsString('Share this article', $result);
        self::assertStringNotContainsString('sharer', $result);
        self::assertStringContainsString('Body.', $result);
    }

    public function testKeepsAClusterThatMixesShareButtonsWithRealLinks(): void
    {
        // Not a share-only cluster: it also holds a content link, so it stays.
        $html = '<div><a href="https://x.com/intent/tweet?url=https://x.test/a">X</a>'
            . '<a href="https://x.test/related">Related story</a></div>';

        self::assertStringContainsString('Related story', $this->cleaned($html));
    }

    public function testRemovesANestedListShareBarIncludingItsLabel(): void
    {
        // A <ul><li> bar is the common markup shape a hand-rolled share widget
        // uses; the climb must see through the list structure to reach the
        // wrapping label, not stop at the first <li> (#627 fix round 1).
        $html = '<article><p>Body.</p>'
            . '<div><span>Share this article</span>'
            . '<ul class="bar">'
            . '<li><a href="https://www.facebook.com/sharer/sharer.php?u=https://x.test/a">FB</a></li>'
            . '<li><a href="https://x.com/intent/tweet?url=https://x.test/a">X</a></li>'
            . '</ul></div></article>';

        $result = $this->cleaned($html);

        self::assertStringNotContainsString('Share this article', $result);
        self::assertStringNotContainsString('sharer', $result);
        self::assertStringNotContainsString('<ul', $result);
        self::assertStringContainsString('Body.', $result);
    }

    public function testKeepsAReddiGuidelinesLinkButRemovesAPlainRedditSubmitLink(): void
    {
        // A path segment boundary, not a bare prefix: "submit-guidelines" is a
        // different endpoint from "submit" and must not match it (#627 fix round 1).
        $kept = $this->cleaned(
            '<div><p>Body.</p><a href="https://reddit.com/submit-guidelines?url=https://x.test/a">Guidelines</a></div>',
        );
        self::assertStringContainsString('reddit.com/submit-guidelines', $kept);

        $removed = $this->cleaned(
            '<div><p>Body.</p><a href="https://reddit.com/submit?url=https://x.test/a">Share</a></div>',
        );
        self::assertStringNotContainsString('reddit.com/submit', $removed);
    }

    public function testRemovesAFacebookSharerPhpLink(): void
    {
        // The 5 Magazine / Nature shape: no "/sharer/" segment, just the bare
        // "sharer.php" file — the "." must count as a boundary too (#627 fix round 2).
        $html = '<div><p>Body.</p>'
            . '<a href="https://www.facebook.com/sharer.php?u=https://5mag.test/a">Share</a></div>';

        self::assertStringNotContainsString('facebook.com/sharer.php', $this->cleaned($html));
    }

    public function testRemovesALinkedInShareArticleLinkWithACamelCasePath(): void
    {
        // Nature's shape: LinkedIn's own share link uses camelCase in the path
        // ("/shareArticle"), which must still match the lowercase endpoint
        // ("linkedin.com/sharearticle") (#627 fix round 3).
        $html = '<div><p>Body.</p>'
            . '<a href="https://www.linkedin.com/shareArticle?url=https://nature.test/a&amp;title=T">Share</a></div>';

        self::assertStringNotContainsString('linkedin.com', $this->cleaned($html));
    }

    public function testKeepsAWaMeLookAlikeDomainThatIsNotTheHostOnlyEndpoint(): void
    {
        // "wa.me" is a host-only endpoint, so it must not gain the file-extension
        // boundary that "facebook.com/sharer" earns from its path segment (#627
        // fix round 3): "wa.me.example.com" is an unrelated domain.
        $html = '<div><p>Body.</p>'
            . '<a href="https://wa.me.example.com/share?u=https://x.test/a">Share</a></div>';

        self::assertStringContainsString('wa.me.example.com', $this->cleaned($html));
    }

    public function testRemovesAClusterWhoseLabelSitsExactlyAtTheBudget(): void
    {
        $label = str_repeat('a', 60);
        $html = '<div><span>' . $label . '</span>'
            . '<a href="https://x.com/intent/tweet?url=https://x.test/a">X</a></div>';

        self::assertStringNotContainsString($label, $this->cleaned($html));
    }

    public function testKeepsAClusterWhoseLabelIsOneCharacterOverTheBudget(): void
    {
        $label = str_repeat('a', 61);
        $html = '<div><span>' . $label . '</span>'
            . '<a href="https://x.com/intent/tweet?url=https://x.test/a">X</a></div>';

        $result = $this->cleaned($html);

        self::assertStringContainsString($label, $result);
        self::assertStringNotContainsString('x.com/intent', $result);
    }

    public function testRemovesAClusterWithAMultibyteLabelUnderTheCharacterBudget(): void
    {
        // 40 "ü" characters: 40 chars by mb_strlen, ~80 bytes by strlen — an
        // mb_strlen -> strlen mutant would wrongly see it as over budget.
        $label = str_repeat('ü', 40);
        $html = '<div><span>' . $label . '</span>'
            . '<a href="https://x.com/intent/tweet?url=https://x.test/a">X</a></div>';

        self::assertStringNotContainsString($label, $this->cleaned($html));
    }

    private function cleaned(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->remover->removeFrom($document);

        return $document->saveHtml();
    }
}
