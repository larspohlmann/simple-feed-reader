<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\MembershipCheckout;
use PHPUnit\Framework\TestCase;

final class MembershipCheckoutTest extends TestCase
{
    public function testFindsAMemberfulCheckoutLinkInTheBody(): void
    {
        // psychedelicalpha.com: the intro cuts to a Memberful subscribe block whose
        // class `join` is too generic to gate on; the checkout link carries the signal (#998).
        self::assertTrue($this->hasCheckout(
            '<article><p>Teaser.</p></article>'
            . '<div class="join"><a href="https://alpha.memberful.com/checkout?plan=99046">Monthly $20</a></div>',
        ));
    }

    public function testMatchesTheEndpointInAnyCase(): void
    {
        self::assertTrue($this->hasCheckout('<a href="https://SITE.MEMBERFUL.COM/Checkout?plan=1">Join</a>'));
    }

    public function testASignInLinkToTheSameProviderIsNotACheckout(): void
    {
        // The same block also links to the members' sign-in endpoint; only the
        // checkout path signals a gate, not a bare membership link.
        self::assertFalse($this->hasCheckout(
            '<a href="https://psychedelicalpha.memberful.com/account?memberful_endpoint=auth">Sign In</a>',
        ));
    }

    public function testSkipsCheckoutLinksInsidePageFurniture(): void
    {
        self::assertFalse($this->hasCheckout(
            '<footer><a href="https://site.memberful.com/checkout?plan=1">Join</a></footer>',
        ));
    }

    public function testAPageWithoutAMembershipCheckoutHasNoSignal(): void
    {
        self::assertFalse($this->hasCheckout('<article><p>No wall here.</p><a href="/about">About</a></article>'));
    }

    private function hasCheckout(string $body): bool
    {
        $document = HtmlDocumentParser::parseOrNull('<html><body>' . $body . '</body></html>');
        self::assertNotNull($document);

        return MembershipCheckout::foundOutsideFurnitureIn($document);
    }
}
