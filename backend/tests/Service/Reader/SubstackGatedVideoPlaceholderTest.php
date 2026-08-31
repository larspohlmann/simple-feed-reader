<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use PHPUnit\Framework\TestCase;

final class SubstackGatedVideoPlaceholderTest extends TestCase
{
    private const string PLAYER =
        '<div class="shows-video-player-container container-abc">'
        . '<div class="settingsControlsContainer-x">'
        . '<p>Playback speed</p><p>×</p><p>Share post</p></div>'
        . '<div><p>0:00</p><p>/</p><p>Preview</p></div></div>';
    private const string TEASER =
        '<p>An ancient intuition is that plants have souls and participate in life.</p>';
    private const string PAYWALL =
        '<div data-testid="paywall" role="region" aria-label="Paywall">'
        . '<h2>Continue reading this post for free.</h2></div>';

    private SubstackGatedVideoPlaceholder $placeholder;

    protected function setUp(): void
    {
        $this->placeholder = new SubstackGatedVideoPlaceholder();
    }

    public function testReplacesTheGatedPlayerWithAPosterLinkingToTheSource(): void
    {
        $result = $this->apply(
            self::PLAYER . self::TEASER . self::PAYWALL,
            'https://cdn.test/og.jpg',
            'https://x.substack.com/p/a',
        );

        self::assertStringNotContainsString('Playback speed', $result);
        self::assertStringNotContainsString('Share post', $result);
        self::assertStringNotContainsString('Continue reading this post for free', $result);
        self::assertStringContainsString('<a href="https://x.substack.com/p/a">', $result);
        self::assertStringContainsString('src="https://cdn.test/og.jpg"', $result);
        self::assertStringContainsString('width="1280"', $result);
        self::assertStringContainsString('An ancient intuition', $result);
    }

    public function testUsesTheCanonicalLinkWhenOgUrlIsAbsent(): void
    {
        $head = '<meta property="og:image" content="https://cdn.test/og.jpg">'
            . '<link rel="canonical" href="https://x.substack.com/p/canonical">';
        $result = $this->applyWithHead($head, self::PLAYER . self::TEASER . self::PAYWALL);

        self::assertStringContainsString('<a href="https://x.substack.com/p/canonical">', $result);
        self::assertStringNotContainsString('Playback speed', $result);
    }

    public function testDoesNothingWhenThereIsNoPaywallLandmark(): void
    {
        $result = $this->apply(
            self::PLAYER . self::TEASER,
            'https://cdn.test/og.jpg',
            'https://x.substack.com/p/a',
        );

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThereIsNoPlayerContainer(): void
    {
        $renamedPlayer =
            '<div class="shows-video-player-renamed container-abc">'
            . '<div class="settingsControlsContainer-x"><p>Playback speed</p><p>Preview</p></div></div>';
        $result = $this->apply(
            $renamedPlayer . self::TEASER . self::PAYWALL,
            'https://cdn.test/og.jpg',
            'https://x.substack.com/p/a',
        );

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringContainsString('Continue reading this post for free', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThereIsNoVideoArticle(): void
    {
        $head = '<meta property="og:image" content="https://cdn.test/og.jpg">'
            . '<meta property="og:url" content="https://x.substack.com/p/a">';
        $body = '<div class="single-post-container"><article class="post">'
            . self::PLAYER . self::TEASER . self::PAYWALL . '</article></div>';
        $result = $this->normalize($this->page($head, $body));

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsMissing(): void
    {
        $head = '<meta property="og:url" content="https://x.substack.com/p/a">';
        $result = $this->applyWithHead($head, self::PLAYER . self::TEASER . self::PAYWALL);

        self::assertStringContainsString('Playback speed', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsNotHttp(): void
    {
        $result = $this->apply(
            self::PLAYER . self::TEASER . self::PAYWALL,
            'ftp://cdn.test/og.jpg',
            'https://x.substack.com/p/a',
        );

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    private function apply(string $inner, string $ogImage, string $ogUrl): string
    {
        $head = '<meta property="og:image" content="' . $ogImage . '">'
            . '<meta property="og:url" content="' . $ogUrl . '">';

        return $this->applyWithHead($head, $inner);
    }

    private function applyWithHead(string $head, string $inner): string
    {
        return $this->normalize($this->page($head, $this->gatedArticle($inner)));
    }

    private function gatedArticle(string $inner): string
    {
        return '<div class="single-post-container" aria-label="Post" role="main">'
            . '<article class="typography podcast-post post shows-post">' . $inner . '</article></div>';
    }

    private function page(string $head, string $body): string
    {
        // The fixture is the input under test, so it keeps its `lang`-less
        // <html> instead of being edited to please the IDE.
        /** @noinspection HtmlRequiredLangAttribute */
        return '<html><head>' . $head . '</head><body>' . $body . '</body></html>';
    }

    private function normalize(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->placeholder->replaceIn($document);

        return $document->saveHtml();
    }
}
