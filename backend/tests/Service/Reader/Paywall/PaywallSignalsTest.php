<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\PaywallSignals;
use PHPUnit\Framework\TestCase;

final class PaywallSignalsTest extends TestCase
{
    private const string BODY = "<article>\n<h1>Headline</h1>\n"
        . '<p>The preview paragraph carries enough prose to stand in for the article body.</p>' . "\n</article>";
    private const string CTA = '<div class="paywall-cta">'
        . '<h2 class="paywall-title">Continue reading this post for free.</h2>'
        . '<button>Claim my free post</button></div>';

    public function testAPremiumDeclarationFlagsAPreview(): void
    {
        self::assertTrue($this->signals($this->page(self::BODY, '{"isAccessibleForFree":false}'))->isPreview());
    }

    public function testAPremiumDeclarationIsTrustedEvenWithoutAGateBlock(): void
    {
        // The publisher declares the article premium but serves the whole body,
        // as mopo.de does for its MOPO+ articles. The declaration is trusted and
        // the reader shows the banner (an accepted #908 trade-off).
        self::assertTrue($this->signals($this->page(self::BODY, '{"isAccessibleForFree":"False"}'))->isPreview());
    }

    public function testAFreeDeclarationIsTrustedOverAGateBlock(): void
    {
        $page = $this->page(self::BODY . self::CTA, '{"isAccessibleForFree":true}');

        self::assertFalse($this->signals($page)->isPreview());
    }

    public function testAnAbsentDeclarationWithAGateBlockFlagsAPreview(): void
    {
        self::assertTrue($this->signals($this->page(self::BODY . self::CTA))->isPreview());
    }

    public function testAnAbsentDeclarationWithoutAGateBlockDoesNotFlag(): void
    {
        self::assertFalse($this->signals($this->page(self::BODY))->isPreview());
    }

    public function testAGateBlockInsidePageFurnitureDoesNotFlagAnUndeclaredPage(): void
    {
        $page = $this->page(self::BODY . '<nav><a class="paywall-link" href="/abo">Abo</a></nav>');

        self::assertFalse($this->signals($page)->isPreview());
    }

    public function testWithoutADocumentTheDeclarationStillDecides(): void
    {
        self::assertFalse(PaywallSignals::fromPage('', null)->isPreview());
        self::assertTrue(
            PaywallSignals::fromPage(
                '<script type="application/ld+json">{"isAccessibleForFree":"False"}</script>',
                null,
            )->isPreview(),
        );
    }

    private function signals(string $html): PaywallSignals
    {
        return PaywallSignals::fromPage($html, HtmlDocumentParser::parseOrNull($html));
    }

    private function page(string $body, ?string $jsonLd = null): string
    {
        $head = $jsonLd === null ? '' : '<script type="application/ld+json">' . $jsonLd . '</script>';

        return "<html><head>{$head}</head><body>\n{$body}\n<footer>Foot</footer>\n</body></html>";
    }
}
