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
        self::assertTrue($this->isPreview($this->page(self::BODY, '{"isAccessibleForFree":false}')));
    }

    public function testAPremiumDeclarationIsTrustedEvenWithoutAGateBlock(): void
    {
        // The publisher declares the article premium but serves the whole body,
        // as mopo.de does for its MOPO+ articles. The declaration is trusted and
        // the reader shows the banner (an accepted #908 trade-off).
        self::assertTrue($this->isPreview($this->page(self::BODY, '{"isAccessibleForFree":"False"}')));
    }

    public function testAFreeDeclarationIsTrustedOverAGateBlock(): void
    {
        self::assertFalse($this->isPreview($this->page(self::BODY . self::CTA, '{"isAccessibleForFree":true}')));
    }

    public function testAnAbsentDeclarationWithAGateBlockFlagsAPreview(): void
    {
        self::assertTrue($this->isPreview($this->page(self::BODY . self::CTA)));
    }

    public function testAnAbsentDeclarationWithAMembershipCheckoutFlagsAPreview(): void
    {
        // psychedelicalpha.com: no declaration, a generic `join` gate class, but a
        // Memberful checkout link carries the signal (#998).
        $checkout = '<div class="join"><a href="https://x.memberful.com/checkout?plan=1">Join</a></div>';

        self::assertTrue($this->isPreview($this->page(self::BODY . $checkout)));
    }

    public function testAnAbsentDeclarationWithoutAGateBlockDoesNotFlag(): void
    {
        self::assertFalse($this->isPreview($this->page(self::BODY)));
    }

    public function testAGateBlockInsidePageFurnitureDoesNotFlagAnUndeclaredPage(): void
    {
        $nav = '<nav><a class="paywall-link" href="/abo">Abo</a></nav>';

        self::assertFalse($this->isPreview($this->page(self::BODY . $nav)));
    }

    public function testWithoutADocumentTheDeclarationStillDecides(): void
    {
        $premium = '<script type="application/ld+json">{"isAccessibleForFree":"False"}</script>';

        self::assertFalse(PaywallSignals::isPreview('', null));
        self::assertTrue(PaywallSignals::isPreview($premium, null));
    }

    private function isPreview(string $html): bool
    {
        return PaywallSignals::isPreview($html, HtmlDocumentParser::parseOrNull($html));
    }

    private function page(string $body, ?string $jsonLd = null): string
    {
        $head = $jsonLd === null ? '' : '<script type="application/ld+json">' . $jsonLd . '</script>';

        return "<html><head>{$head}</head><body>\n{$body}\n<footer>Foot</footer>\n</body></html>";
    }
}
