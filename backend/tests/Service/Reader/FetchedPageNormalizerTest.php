<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\CustomElementUnwrapper;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\ImageWrapperClassRemover;
use App\Service\Reader\LazyImageSources;
use App\Service\Reader\ShareIntentLinkRemover;
use App\Service\Reader\ShareWidgetRemover;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use PHPUnit\Framework\TestCase;

final class FetchedPageNormalizerTest extends TestCase
{
    private FetchedPageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FetchedPageNormalizer(
            new CustomElementUnwrapper(),
            new LazyImageSources(),
            new ShareWidgetRemover(),
            new ShareIntentLinkRemover(),
            new SubstackGatedVideoPlaceholder(),
            new ImageWrapperClassRemover(),
        );
    }

    public function testCollapsesSingleChildDivChains(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="a"><div class="b"><div class="c"><p>Text</p></div></div></div></body></html>';

        $collapsed = $this->collapsed($html);

        // The two outer wrappers are gone; the innermost div (whose child is a
        // <p>, not a <div>) survives as the direct parent of the paragraph.
        self::assertStringNotContainsString('class="a"', $collapsed);
        self::assertStringNotContainsString('class="b"', $collapsed);
        self::assertStringContainsString('<div class="c"><p>Text</p></div>', $collapsed);
    }

    public function testCollapsesWrapperChainsIndentedWithWhitespace(): void
    {
        // Real block-component markup indents its wrappers, so whitespace text
        // sits between each <div> and its single child. That whitespace is not
        // the wrapper's own content; without trimming it, the chain would never
        // collapse.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = "<html><body><div class=\"a\">\n<div class=\"b\">\n"
            . "<div class=\"c\">\n<p>Text</p>\n</div>\n</div>\n</div></body></html>";

        $collapsed = $this->collapsed($html);

        self::assertStringNotContainsString('class="a"', $collapsed);
        self::assertStringNotContainsString('class="b"', $collapsed);
        self::assertStringContainsString('<p>Text</p>', $collapsed);
    }

    public function testKeepsDivWithOwnText(): void
    {
        // The div carries its own text, so it is content and not a wrapper: no
        // chain to collapse. (A div with several element children is covered by
        // testCollapseReturnsNullWhenNoWrapperChains — the same "not a single-
        // child wrapper" branch, so it is not duplicated here.)
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="keep">intro <div>nested</div></div></body></html>';

        self::assertNull($this->normalizer->collapseWrapperChains($html));
    }

    public function testHeadingSurvivesWrapperCollapse(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div><div><h2 id="s1">Section</h2></div></div></body></html>';

        self::assertStringContainsString('<h2 id="s1">Section</h2>', $this->collapsed($html));
    }

    public function testCollapseReturnsNullWhenNoWrapperChains(): void
    {
        // A div with several element children is a real container, not a
        // single-child wrapper, so there is no chain to collapse: the method
        // returns null and ArticleExtractor skips the second extraction.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div class="keep"><p>One</p><p>Two</p></div></body></html>';

        self::assertNull($this->normalizer->collapseWrapperChains($html));
    }

    public function testRemovesScreenReaderOnlyElements(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body>'
            . '<span class="visually-hidden">Image source,</span>'
            . '<span class="ssrcss-1f39n02-VisuallyHidden e16en2lz0">Image caption,</span>'
            . '<span class="sr-only">skip</span>'
            . '<p class="visible">Body</p>'
            . '</body></html>';

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString('Image source,', $normalized);
        self::assertStringNotContainsString('Image caption,', $normalized);
        self::assertStringNotContainsString('skip', $normalized);
        self::assertStringContainsString('Body', $normalized);
    }

    public function testStripsAShareWidgetBeforeReadabilitySeesIt(): void
    {
        // A Shariff bar in the raw page is gone after normalize(), so its
        // "teilen" labels never reach readability or lead the article (#582).
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><article><div class="shariff">'
            . '<ul class="shariff-buttons"><li>Facebook teilen</li></ul></div>'
            . '<p>Body text long enough to be real content.</p></article></body></html>';

        $normalized = $this->normalizer->normalize($html)?->saveHtml() ?? '';

        self::assertStringNotContainsString('teilen', $normalized);
        self::assertStringContainsString('Body text', $normalized);
    }

    public function testStripsAShareIntentLinkBeforeReadabilitySeesIt(): void
    {
        // A hand-rolled Bluesky share link carrying the page's own URL is gone
        // after normalize() (#627); it is not a plugin widget, so ShareWidgetRemover
        // alone would leave it for readability to keep.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><article><p>Body text long enough to be real content.</p>'
            . '<a href="https://bsky.app/intent/compose?text=https://canarymedia.com/x">Share</a>'
            . '</article></body></html>';

        $normalized = $this->normalizer->normalize($html)?->saveHtml() ?? '';

        self::assertStringNotContainsString('bsky.app', $normalized);
        self::assertStringContainsString('Body text', $normalized);
    }

    public function testEmptyInputYieldsNull(): void
    {
        self::assertNull($this->normalizer->normalize(''));
        self::assertNull($this->normalizer->normalize('   '));
    }

    public function testUmlautsSurviveNormalization(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><div><div><p>Grüße from Köln</p></div></div></body></html>';

        $normalized = $this->normalized($html);

        // The HTML5 serializer keeps non-ASCII as UTF-8; html_entity_decode is a
        // harmless no-op that also covers an entity-encoding serializer.
        self::assertStringContainsString('Grüße from Köln', html_entity_decode($normalized));
    }

    public function testRemovesAnOrphanIconGlyphAndPrunesTheHoldersItEmpties(): void
    {
        // U+E80F is an icon-font glyph the sanitizer's class strip would orphan.
        // It sits in a <span>, in a <p>, in a <div> that holds nothing else:
        // stripping it empties all three, which are pruned from the inside out.
        // A plain paragraph precedes the glyph, so a scan that stopped at the
        // first glyph-free node (rather than skipping it) would leave the glyph
        // behind. The <p> keeps whitespace around the glyph span, so an
        // untrimmed emptiness check would leave the blank <p> in place. The
        // surrounding paragraphs must stay.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><section><p>Intro paragraph.</p>'
            . "<div><p> <span>\u{E80F}</span> </p></div>"
            . '<p>Quote body</p></section></body></html>';

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString("\u{E80F}", $normalized);
        self::assertStringNotContainsString('<span>', $normalized);
        self::assertStringNotContainsString('<div>', $normalized);
        self::assertDoesNotMatchRegularExpression('/<p>\s*<\/p>/', $normalized);
        self::assertStringContainsString('Intro paragraph.', $normalized);
        self::assertStringContainsString('Quote body', $normalized);
    }

    public function testKeepsAnEmptiedHolderThatStillCarriesAnImage(): void
    {
        // Stripping the glyph empties the <span> of text, but its <img> makes it
        // meaningful, so the holder must survive.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = "<html><body><span>\u{E80F}"
            . '<img src="https://images.example.com/a.png" alt=""></span></body></html>';

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString("\u{E80F}", $normalized);
        self::assertStringContainsString('images.example.com/a.png', $normalized);
        self::assertStringContainsString('<img', $normalized);
    }

    public function testStripsAGlyphButKeepsTheTextAroundIt(): void
    {
        /** @noinspection HtmlRequiredLangAttribute */
        $html = "<html><body><p>Before\u{E80F}After</p></body></html>";

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString("\u{E80F}", $normalized);
        self::assertStringContainsString('BeforeAfter', html_entity_decode($normalized));
    }

    public function testStripsAnInlineScriptThatBuildsAnHtmlString(): void
    {
        // The script's JavaScript builds an HTML string ('<div>…</div>', a
        // paywall or banner injector). Removed from the raw source — bounded by
        // the real </script> — none of it can reach the extraction.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><p>Real body.</p>'
            . '<script>var banner = \'<div class="ad">Buy now</div>\';'
            . ' document.currentScript.insertAdjacentHTML("beforebegin", banner);</script>'
            . '</body></html>';

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString('currentScript', $normalized);
        self::assertStringNotContainsString('insertAdjacentHTML', $normalized);
        self::assertStringNotContainsString('Buy now', $normalized);
        self::assertStringContainsString('Real body.', $normalized);
    }

    public function testStripsAStyleBlock(): void
    {
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><style>.ad{color:red}</style><p>Body</p></body></html>';

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString('color:red', $normalized);
        self::assertStringContainsString('Body', $normalized);
    }

    public function testRestoresTheSourceOfALazyLoadedImage(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><body><figure><img src="data:image/gif;base64,R0lGOD"'
            . ' data-lazy-src="https://images.example.com/photo.jpg"></figure></body></html>';

        $normalized = $this->normalized($html);

        self::assertStringContainsString('<img src="https://images.example.com/photo.jpg"', $normalized);
        self::assertStringNotContainsString('data:image', $normalized);
    }

    public function testNormalizeReplacesTheSubstackGatedPlayerWithThePoster(): void
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        $html = '<html><head>'
            . '<meta property="og:image" content="https://cdn.test/og.jpg">'
            . '<meta property="og:url" content="https://x.substack.com/p/a">'
            . '</head><body>'
            . '<div class="single-post-container" aria-label="Post" role="main">'
            . '<article class="typography podcast-post post shows-post">'
            . '<div class="shows-video-player-container container-abc">'
            . '<div class="settingsControlsContainer-x"><p>Playback speed</p><p>Share post</p></div></div>'
            . '<p>An ancient intuition is that plants have souls and participate in the wider life of the world.</p>'
            . '<div data-testid="paywall" role="region" aria-label="Paywall">'
            . '<h2>Continue reading this post for free.</h2></div>'
            . '</article></div></body></html>';

        $normalized = $this->normalized($html);

        self::assertStringNotContainsString('Playback speed', $normalized);
        self::assertStringNotContainsString('Continue reading this post for free', $normalized);
        self::assertStringContainsString('<a href="https://x.substack.com/p/a">', $normalized);
        self::assertStringContainsString('src="https://cdn.test/og.jpg"', $normalized);
        self::assertStringContainsString('An ancient intuition', $normalized);
    }

    public function testUnwrapsACustomElementSoItsPhotoReachesReadability(): void
    {
        $normalized = $this->normalized(
            '<html lang="en"><body><article><sh-background-transition>'
            . '<div><img src="https://x.test/a.jpg" alt=""></div>'
            . '</sh-background-transition><p>Caption.</p></article></body></html>',
        );

        self::assertStringNotContainsString('sh-background-transition', $normalized);
        self::assertStringContainsString('<img src="https://x.test/a.jpg" alt="">', $normalized);
    }

    public function testStripsTheClassOfATextlessPictureWrapperBeforeReadabilityScoresIt(): void
    {
        $normalized = $this->normalized(
            '<html lang="en"><body><article><div class="Theme-Layer-ResponsiveMedia">'
            . '<div class="ResponsiveMedia--image__inner"><img src="https://x.test/a.jpg" alt=""></div></div>'
            . '<p>Caption.</p></article></body></html>'
        );

        self::assertStringNotContainsString('ResponsiveMedia', $normalized);
        self::assertStringContainsString('<img src="https://x.test/a.jpg" alt="">', $normalized);
    }

    /** Order matters: the share-widget fingerprint is read before the picture wrapper's class goes. */
    public function testAShareWidgetThatHoldsOnlyAnIconIsStillRemoved(): void
    {
        $normalized = $this->normalized(
            '<html lang="en"><body><article><p>Text.</p><div class="sharedaddy">'
            . '<img src="https://x.test/icon.png" alt=""></div></article></body></html>'
        );

        self::assertStringNotContainsString('icon.png', $normalized);
    }

    /** normalize() then serialize; the fixtures under test always parse. */
    private function normalized(string $html): string
    {
        $document = $this->normalizer->normalize($html);
        self::assertNotNull($document);

        return $document->saveHtml();
    }

    /** collapseWrapperChains() then serialize; used only where a chain collapses. */
    private function collapsed(string $html): string
    {
        $document = $this->normalizer->collapseWrapperChains($html);
        self::assertNotNull($document);

        return $document->saveHtml();
    }
}
