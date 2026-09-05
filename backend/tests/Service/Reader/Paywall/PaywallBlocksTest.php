<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\PaywallBlocks;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class PaywallBlocksTest extends TestCase
{
    private const string SUBSTACK_CTA = "<div class=\"paywall-cta\">\n"
        . "<h2 class=\"paywall-title\">Continue reading this post for free.</h2>\n"
        . "<button>Claim my free post</button>\n</div>";

    public function testCollectsAWrapperAndItsChildInDocumentOrder(): void
    {
        $texts = PaywallBlocks::textsIn($this->document('<article><p>Teaser.</p>' . self::SUBSTACK_CTA . '</article>'));

        self::assertSame(
            ['Continuereadingthispostforfree.Claimmyfreepost', 'Continuereadingthispostforfree.'],
            $texts,
        );
    }

    public function testMatchesTheClassFragmentInAnyCaseAndAnyPosition(): void
    {
        $document = $this->document(
            '<div class="PayWall">Upper.</div><div class="duv-paywall-preview svelte-1">Zeit.</div>',
        );

        self::assertSame(['Upper.', 'Zeit.'], PaywallBlocks::textsIn($document));
    }

    public function testMatchesAGatedRegionNamedSubscriptionOnly(): void
    {
        // jungle.world (Drupal): the body wrapper carries `subscription-only`,
        // the call to action `subscription-only-block`, and no `paywall` anywhere.
        $document = $this->document(
            '<div class="body-wrapper subscription-only"><p>Text.</p>'
            . '<div class="subscription-only-block"><h2>Noch kein Abonnement?</h2></div></div>'
            . '<p class="subscribers-only">Members.</p>',
        );

        self::assertSame(
            ['Text.NochkeinAbonnement?', 'NochkeinAbonnement?', 'Members.'],
            PaywallBlocks::textsIn($document),
        );
    }

    public function testASubscribeWidgetIsNotAPaywallBlock(): void
    {
        $document = $this->document(
            '<div class="subscribe-widget subscription-form">Subscribe to get new posts.</div>',
        );

        self::assertSame([], PaywallBlocks::textsIn($document));
    }

    public function testSkipsBlocksInsidePageFurniture(): void
    {
        $document = $this->document(
            '<nav><a class="paywall-link" href="/abo">Abo</a></nav>'
            . '<aside class="paywall-teaser">Side.</aside>'
            . '<footer><p class="paywall-info">Foot.</p></footer>'
            . '<main><p class="paywall-cta">Main.</p></main>',
        );

        self::assertSame(['Main.'], PaywallBlocks::textsIn($document));
    }

    public function testSkipsAnEmptyBlock(): void
    {
        $document = $this->document('<div class="paywall-fade"></div><p class="paywall-title">  Title  </p>');

        self::assertSame(['Title'], PaywallBlocks::textsIn($document));
    }

    public function testAPageWithoutPaywallClassesYieldsNothing(): void
    {
        self::assertSame([], PaywallBlocks::textsIn($this->document('<p class="lead">No wall here.</p>')));
    }

    public function testAStateClassOnTheDocumentRootIsNotAGatedBlock(): void
    {
        // mopo.de tags every MOPO+ article template with `has-paywall` on
        // <body>; the whole page is not a gated region.
        $document = HtmlDocumentParser::parseOrNull(
            '<html class="has-paywall"><body class="article has-paywall">'
            . '<article><p>The full article, served in one piece.</p></article></body></html>',
        );
        self::assertNotNull($document);

        self::assertSame([], PaywallBlocks::textsIn($document));
    }

    private function document(string $body): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull('<html><body>' . $body . '</body></html>');
        self::assertNotNull($document);

        return $document;
    }
}
