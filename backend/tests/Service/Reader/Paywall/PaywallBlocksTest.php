<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\PaywallBlocks;
use PHPUnit\Framework\TestCase;

final class PaywallBlocksTest extends TestCase
{
    public function testFindsAGatedCallToAction(): void
    {
        self::assertTrue($this->hasGate('<article><p>Teaser.</p><div class="paywall-cta">Read on</div></article>'));
    }

    public function testMatchesTheClassFragmentInAnyCaseAndAnyPosition(): void
    {
        self::assertTrue($this->hasGate('<div class="PayWall">Upper.</div>'));
        self::assertTrue($this->hasGate('<div class="duv-paywall-preview svelte-1">Zeit.</div>'));
    }

    public function testMatchesEverySubscriberOnlyVariant(): void
    {
        // jungle.world (Drupal): the body wrapper carries `subscription-only`.
        self::assertTrue($this->hasGate('<div class="body-wrapper subscription-only"><p>Text.</p></div>'));
        self::assertTrue($this->hasGate('<p class="subscriber-only">A.</p>'));
        self::assertTrue($this->hasGate('<p class="subscribers-only">B.</p>'));
    }

    public function testAFadeOrTruncationClassIsNoLongerAGateBlock(): void
    {
        // #908 dropped the fade family; the schema.org declaration now carries the
        // ZEIT-style soft gate. A faded or truncated region alone does not flag.
        self::assertFalse($this->hasGate(
            '<div class="paragraph--faded"><p>Trails off.</p></div>'
            . '<div class="article-body fade-out"><p>One.</p></div>'
            . '<section class="content-truncated"><p>Three.</p></section>',
        ));
    }

    public function testASubscribeWidgetIsNotAGateBlock(): void
    {
        self::assertFalse($this->hasGate('<div class="subscribe-widget subscription-form">Subscribe for posts.</div>'));
    }

    public function testSkipsBlocksInsidePageFurniture(): void
    {
        self::assertFalse($this->hasGate(
            '<nav><a class="paywall-link" href="/abo">Abo</a></nav>'
            . '<aside class="paywall-teaser">Side.</aside>'
            . '<footer><p class="paywall-info">Foot.</p></footer>',
        ));
    }

    public function testFindsABlockOutsideFurnitureWhenFurnitureAlsoCarriesOne(): void
    {
        self::assertTrue($this->hasGate(
            '<nav><a class="paywall-link" href="/abo">Abo</a></nav><main><p class="paywall-cta">Main.</p></main>',
        ));
    }

    public function testAPageWithoutPaywallClassesHasNoGateBlock(): void
    {
        self::assertFalse($this->hasGate('<p class="lead">No wall here.</p>'));
    }

    public function testAStateClassOnTheDocumentRootIsNotAGateBlock(): void
    {
        // mopo.de tags every MOPO+ article template with `has-paywall` on <body>;
        // the whole page is not a gated region.
        $document = HtmlDocumentParser::parseOrNull(
            '<html class="has-paywall"><body class="article has-paywall">'
            . '<article><p>The full article, served in one piece.</p></article></body></html>',
        );
        self::assertNotNull($document);

        self::assertFalse(PaywallBlocks::existOutsideFurnitureIn($document));
    }

    private function hasGate(string $body): bool
    {
        $document = HtmlDocumentParser::parseOrNull('<html><body>' . $body . '</body></html>');
        self::assertNotNull($document);

        return PaywallBlocks::existOutsideFurnitureIn($document);
    }
}
