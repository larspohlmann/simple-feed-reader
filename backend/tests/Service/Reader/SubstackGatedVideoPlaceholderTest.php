<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\GatedMediaContext;
use App\Service\Reader\SubstackGatedVideoPlaceholder;
use PHPUnit\Framework\TestCase;

final class SubstackGatedVideoPlaceholderTest extends TestCase
{
    private SubstackGatedVideoPlaceholder $placeholder;

    protected function setUp(): void
    {
        $this->placeholder = new SubstackGatedVideoPlaceholder();
    }

    public function testReplacesTheGatedPlayerWithAPosterLinkingToTheSource(): void
    {
        $body = '<div class="single-post-container"><article class="podcast-post post shows-post">'
            . '<div class="player"><p>Playback speed</p><p>Share post</p><p>0:00</p><p>Preview</p></div>'
            . '<p>An ancient intuition is that plants have souls and participate in life.</p>'
            . '<div role="region" aria-label="Paywall"><h2>Continue reading this post for free.</h2></div>'
            . '</article></div>';
        $context = new GatedMediaContext(
            'https://rupertsheldrake.substack.com/p/the-souls-of-plants',
            'https://substackcdn.com/image/og.jpg',
        );

        $acted = $this->apply($body, $context, $result);

        self::assertTrue($acted);
        self::assertStringNotContainsString('Playback speed', $result);
        self::assertStringContainsString('substackcdn.com/image/og.jpg', $result);
        self::assertStringContainsString('rupertsheldrake.substack.com/p/the-souls-of-plants', $result);
        self::assertStringContainsString('An ancient intuition', $result);
    }

    public function testDoesNothingWhenThereIsNoPaywallLandmark(): void
    {
        $body = '<div class="single-post-container"><article class="post">'
            . '<p>A full free article with real prose that is not gated at all here.</p>'
            . '</article></div>';
        $context = new GatedMediaContext('https://x.substack.com/p/free', 'https://x/og.jpg');

        self::assertFalse($this->apply($body, $context, $result));
        self::assertStringContainsString('A full free article', $result);
    }

    public function testDoesNothingWhenThePosterUrlIsMissing(): void
    {
        $body = '<div><div role="region" aria-label="Paywall"><p>Gated.</p></div>'
            . '<article class="podcast-post"><div class="player"><p>Preview</p></div></article></div>';
        $context = new GatedMediaContext('https://x.substack.com/p/a', null);

        self::assertFalse($this->apply($body, $context, $result));
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
