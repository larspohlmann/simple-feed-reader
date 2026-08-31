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

    private function cleaned(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->remover->removeFrom($document);

        return $document->saveHtml();
    }
}
