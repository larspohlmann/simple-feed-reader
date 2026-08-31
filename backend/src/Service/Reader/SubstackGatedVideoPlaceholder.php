<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * A paywalled Substack podcast/video post extracts to nothing but dead player
 * chrome ("Playback speed / Share post / 0:00 / Preview") above a short teaser.
 * This drops the dead player and the paywall landmark and puts a poster image
 * linking to the source article in their place, so the teaser reads inline with
 * a clear way back to the original (#627, #748).
 */
final readonly class SubstackGatedVideoPlaceholder implements GatedMediaPlaceholderInterface
{
    private const string PAYWALL = '[aria-label="Paywall"], [data-testid="paywall"]';
    private const string ARTICLE = 'article.podcast-post, article.shows-post';
    private const string PLAYER = '.shows-video-player-container';

    public function replaceIn(\Dom\HTMLDocument $body, GatedMediaContext $context): bool
    {
        if (!$this->isGatedVideoPost($body, $context->posterUrl)) {
            return false;
        }

        $this->replacePlayerWithPoster($body, $context->sourceUrl, (string) $context->posterUrl);

        return true;
    }

    private function isGatedVideoPost(\Dom\HTMLDocument $body, ?string $posterUrl): bool
    {
        if ($posterUrl === null || preg_match('#^https?://#i', $posterUrl) !== 1) {
            return false;
        }
        if ($body->querySelector(self::PAYWALL) === null) {
            return false;
        }

        return $body->querySelector(self::ARTICLE) !== null;
    }

    private function replacePlayerWithPoster(\Dom\HTMLDocument $body, string $sourceUrl, string $posterUrl): void
    {
        $body->querySelector(self::PAYWALL)?->remove();
        $body->querySelector(self::PLAYER)?->remove();

        $article = $body->querySelector(self::ARTICLE);
        if ($article === null) {
            return;
        }

        $article->insertBefore($this->posterLink($body, $sourceUrl, $posterUrl), $article->firstChild);
    }

    private function posterLink(\Dom\HTMLDocument $document, string $sourceUrl, string $posterUrl): \Dom\Element
    {
        $link = $document->createElement('a');
        $link->setAttribute('href', $sourceUrl);

        $image = $document->createElement('img');
        $image->setAttribute('src', $posterUrl);
        $image->setAttribute('alt', 'Video — open the original article to watch');
        $image->setAttribute('width', '1280');
        $image->setAttribute('height', '720');
        $link->appendChild($image);

        return $link;
    }
}
