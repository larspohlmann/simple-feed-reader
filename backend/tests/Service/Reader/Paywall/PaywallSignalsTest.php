<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Paywall\PaywallSignals;
use PHPUnit\Framework\TestCase;

final class PaywallSignalsTest extends TestCase
{
    private const string FIRST = '<p>The first preview paragraph carries enough prose to be kept by readability.</p>';
    private const string SECOND = '<p>The second preview paragraph is where the free part of the article ends.</p>';
    private const string CTA = '<div class="paywall-cta">'
        . '<h2 class="paywall-title">Continue reading this post for free.</h2>'
        . '<button>Claim my free post</button></div>';
    private const string PREVIEW_BODY = self::FIRST . self::SECOND;

    public function testTheJsonLdDeclarationAloneFlagsAPaywall(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY, '{"@type":"Article","isAccessibleForFree":false}'));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAJsonLdFreeDeclarationDecidesAloneOverAPaywallBlock(): void
    {
        $signals = $this->signals(
            $this->page(self::PREVIEW_BODY . self::CTA, '{"@type":"Article","isAccessibleForFree":true}'),
        );

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAPaywallBlockBelowTheLastExtractedParagraphFlagsAPreview(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAPaywallBannerAboveTheArticleDoesNotFlagAFreeArticle(): void
    {
        $banner = '<div class="paywall-banner"><p>Support independent journalism: become a member.</p></div>';
        $signals = $this->signals($this->page($banner . self::PREVIEW_BODY));

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAPromoBoxBetweenTwoExtractedParagraphsDoesNotFlagAFreeArticle(): void
    {
        $signals = $this->signals($this->page(self::FIRST . self::CTA . self::SECOND));

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testProseThatMentionsAPaywallIsNotASignal(): void
    {
        $prose = '<p>Some sites hide their best writing behind a paywall, and that is their right.</p>';
        $signals = $this->signals($this->page(self::FIRST . $prose));

        self::assertFalse($signals->isPreview(self::FIRST . $prose));
    }

    public function testACtaTheCleanersLeftInTheBodyStillCountsFromTheLastProseParagraph(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY . '<p>Continue reading this post for free.</p>'));
    }

    public function testWhenTheLastParagraphCannotBeFoundABlockAbsentFromTheBodyCounts(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertTrue($signals->isPreview('<p>A paragraph a cleaner rewrote beyond recognition.</p>'));
    }

    public function testWhenTheLastParagraphCannotBeFoundABlockStillInTheBodyDoesNotCount(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));
        $html = '<p>Rewritten.</p><p>Continue reading this post for free. Claim my free post</p>';

        self::assertFalse($signals->isPreview($html));
    }

    public function testAPageWithoutADocumentCanOnlyBeDeclaredPaywalled(): void
    {
        self::assertFalse(PaywallSignals::fromPage('', null)->isPreview(self::PREVIEW_BODY));
        self::assertTrue(
            PaywallSignals::fromPage(
                '<script type="application/ld+json">{"isAccessibleForFree":"False"}</script>',
                null,
            )->isPreview(self::PREVIEW_BODY),
        );
    }

    public function testAnEmptyBodyIsNeverAPreviewWithoutADeclaration(): void
    {
        $signals = $this->signals($this->page(self::PREVIEW_BODY . self::CTA));

        self::assertFalse($signals->isPreview(''));
    }

    public function testAGatedWrapperAroundTheWholeArticleCountsByTheCallToActionItAdds(): void
    {
        // jungle.world: `subscription-only` wraps the preview AND the call to
        // action, so no paragraph stands outside a block and the fallback decides.
        $wrapper = '<div class="body-wrapper subscription-only">' . self::PREVIEW_BODY
            . '<div class="subscription-only-block"><h2>Noch kein Abonnement?</h2>'
            . '<p>Um diesen Inhalt zu lesen, wird ein Online-Abo benötigt.</p></div></div>';
        $signals = $this->signals($this->page($wrapper));

        self::assertTrue($signals->isPreview(self::PREVIEW_BODY));
    }

    public function testAGatedWrapperThatAddsNothingBeyondTheBodyDoesNotCount(): void
    {
        $signals = $this->signals($this->page('<div class="paywall-container">' . self::PREVIEW_BODY . '</div>'));

        self::assertFalse($signals->isPreview(self::PREVIEW_BODY));
    }

    private function signals(string $html): PaywallSignals
    {
        return PaywallSignals::fromPage($html, HtmlDocumentParser::parseOrNull($html));
    }

    private function page(string $article, ?string $jsonLd = null): string
    {
        $head = $jsonLd === null ? '' : '<script type="application/ld+json">' . $jsonLd . '</script>';

        return "<html><head>{$head}</head><body>\n<nav><a href=\"/\">Home</a></nav>\n"
            . "<article>\n<h1>Headline</h1>\n{$article}\n</article>\n<footer>Foot</footer>\n</body></html>";
    }
}
