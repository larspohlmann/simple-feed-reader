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
        '<p>An ancient intuition is that plants have souls and participate in the wider life of the world.</p>';
    private const string PAYWALL =
        '<div data-testid="paywall" role="region" aria-label="Paywall">'
        . '<h2>Continue reading this post for free.</h2></div>';

    private SubstackGatedVideoPlaceholder $placeholder;

    protected function setUp(): void
    {
        $this->placeholder = new SubstackGatedVideoPlaceholder();
    }

    public function testInsertsThePosterImmediatelyBeforeTheTeaser(): void
    {
        $document = $this->replaceIn(
            $this->page(
                $this->ogHead('https://cdn.test/og.jpg', 'https://x.substack.com/p/a'),
                $this->gatedArticle(self::PLAYER . self::TEASER . self::PAYWALL),
            ),
        );
        $result = $document->saveHtml();

        self::assertStringNotContainsString('shows-video-player-container', $result);
        self::assertStringNotContainsString('Playback speed', $result);
        self::assertStringNotContainsString('Continue reading this post for free', $result);
        self::assertStringContainsString('An ancient intuition', $result);

        $poster = $document->querySelector('a[href="https://x.substack.com/p/a"]');
        self::assertNotNull($poster);
        self::assertSame('https://cdn.test/og.jpg', $poster->querySelector('img')?->getAttribute('src'));
        $teaser = $this->teaser($document);
        self::assertSame($teaser, $poster->nextElementSibling, 'the poster precedes the teaser paragraph');
    }

    public function testUsesTheCanonicalLinkWhenOgUrlIsAbsent(): void
    {
        $head = '<meta property="og:image" content="https://cdn.test/og.jpg">'
            . '<link rel="canonical" href="https://x.substack.com/p/canonical">';
        $result = $this->replaceIn(
            $this->page($head, $this->gatedArticle(self::PLAYER . self::TEASER . self::PAYWALL)),
        )->saveHtml();

        self::assertStringContainsString('<a href="https://x.substack.com/p/canonical">', $result);
        self::assertStringNotContainsString('Playback speed', $result);
    }

    public function testRemovesTheChromeButSkipsThePosterWhenNoTeaserIsLongEnough(): void
    {
        $shortParagraph = '<p>Watch below.</p>';
        $result = $this->replaceIn(
            $this->page(
                $this->ogHead('https://cdn.test/og.jpg', 'https://x.substack.com/p/a'),
                $this->gatedArticle(self::PLAYER . $shortParagraph . self::PAYWALL),
            ),
        )->saveHtml();

        self::assertStringNotContainsString('shows-video-player-container', $result);
        self::assertStringNotContainsString('Continue reading this post for free', $result);
        self::assertStringContainsString('Watch below.', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThereIsNoPaywallLandmark(): void
    {
        $result = $this->replaceIn(
            $this->page(
                $this->ogHead('https://cdn.test/og.jpg', 'https://x.substack.com/p/a'),
                $this->gatedArticle(self::PLAYER . self::TEASER),
            ),
        )->saveHtml();

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThereIsNoPlayerContainer(): void
    {
        $renamedPlayer =
            '<div class="shows-video-player-renamed container-abc">'
            . '<div class="settingsControlsContainer-x"><p>Playback speed</p><p>Preview</p></div></div>';
        $result = $this->replaceIn(
            $this->page(
                $this->ogHead('https://cdn.test/og.jpg', 'https://x.substack.com/p/a'),
                $this->gatedArticle($renamedPlayer . self::TEASER . self::PAYWALL),
            ),
        )->saveHtml();

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringContainsString('Continue reading this post for free', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThereIsNoVideoArticle(): void
    {
        $body = '<div class="single-post-container"><article class="post">'
            . self::PLAYER . self::TEASER . self::PAYWALL . '</article></div>';
        $result = $this->replaceIn(
            $this->page($this->ogHead('https://cdn.test/og.jpg', 'https://x.substack.com/p/a'), $body),
        )->saveHtml();

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsMissing(): void
    {
        $head = '<meta property="og:url" content="https://x.substack.com/p/a">';
        $result = $this->replaceIn(
            $this->page($head, $this->gatedArticle(self::PLAYER . self::TEASER . self::PAYWALL)),
        )->saveHtml();

        self::assertStringContainsString('Playback speed', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsNotHttp(): void
    {
        $result = $this->replaceIn(
            $this->page(
                $this->ogHead('ftp://cdn.test/og.jpg', 'https://x.substack.com/p/a'),
                $this->gatedArticle(self::PLAYER . self::TEASER . self::PAYWALL),
            ),
        )->saveHtml();

        self::assertStringContainsString('Playback speed', $result);
        self::assertStringNotContainsString('<img', $result);
    }

    private function ogHead(string $ogImage, string $ogUrl): string
    {
        return '<meta property="og:image" content="' . $ogImage . '">'
            . '<meta property="og:url" content="' . $ogUrl . '">';
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

    private function replaceIn(string $html): \Dom\HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->placeholder->replaceIn($document);

        return $document;
    }

    private function teaser(\Dom\HTMLDocument $document): \Dom\Element
    {
        foreach ($document->querySelectorAll('article p') as $paragraph) {
            if (str_contains((string) $paragraph->textContent, 'An ancient intuition')) {
                return $paragraph;
            }
        }

        self::fail('teaser paragraph not found');
    }
}
