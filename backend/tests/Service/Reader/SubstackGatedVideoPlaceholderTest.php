<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\GatedMediaContext;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use PHPUnit\Framework\TestCase;

final class SubstackGatedVideoPlaceholderTest extends TestCase
{
    private const string PLAYER =
        '<div class="shows-video-player-container container-abc">'
        . '<div class="settingsControlsContainer-def">'
        . '<p>Playback speed</p><p>Share post</p><p>0:00</p><p>Preview</p></div></div>';
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
        $body = $this->gatedPost(self::PLAYER . self::TEASER . self::PAYWALL);
        $context = new GatedMediaContext(
            'https://rupertsheldrake.substack.com/p/the-souls-of-plants',
            'https://substackcdn.com/image/og.jpg',
        );

        $acted = $this->apply($body, $context, $result);

        self::assertTrue($acted);
        self::assertStringNotContainsString('Playback speed', $result);
        self::assertStringNotContainsString('Continue reading this post for free', $result);
        self::assertStringContainsString('substackcdn.com/image/og.jpg', $result);
        self::assertStringContainsString('rupertsheldrake.substack.com/p/the-souls-of-plants', $result);
        self::assertStringContainsString('An ancient intuition', $result);
    }

    public function testDoesNothingWhenThereIsNoPaywallLandmark(): void
    {
        $body = $this->gatedPost(self::PLAYER . self::TEASER);
        $context = new GatedMediaContext('https://x.substack.com/p/free', 'https://x/og.jpg');

        self::assertFalse($this->apply($body, $context, $result));
        self::assertStringContainsString('An ancient intuition', $result);
    }

    public function testDoesNothingWhenThereIsNoVideoArticle(): void
    {
        $body = '<div class="single-post-container"><article class="post">'
            . self::TEASER . self::PAYWALL . '</article></div>';
        $context = new GatedMediaContext('https://x.substack.com/p/plain', 'https://x/og.jpg');

        self::assertFalse($this->apply($body, $context, $result));
        self::assertStringContainsString('An ancient intuition', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsMissing(): void
    {
        $body = $this->gatedPost(self::PLAYER . self::TEASER . self::PAYWALL);
        $context = new GatedMediaContext('https://x.substack.com/p/a', null);

        self::assertFalse($this->apply($body, $context, $result));
    }

    public function testDoesNothingWhenThePosterUrlIsNotHttp(): void
    {
        $body = $this->gatedPost(self::PLAYER . self::TEASER . self::PAYWALL);
        $context = new GatedMediaContext('https://x.substack.com/p/a', 'ftp://x/og.jpg');

        self::assertFalse($this->apply($body, $context, $result));
    }

    private function gatedPost(string $inner): string
    {
        return '<div class="single-post-container" aria-label="Post" role="main">'
            . '<article class="typography podcast-post post shows-post">' . $inner . '</article></div>';
    }

    /** @param-out string $result */
    private function apply(string $bodyHtml, GatedMediaContext $context, ?string &$result): bool
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $acted = $this->placeholder->replaceIn($document, $context);
        $result = $document->saveHtml();

        return $acted;
    }
}
