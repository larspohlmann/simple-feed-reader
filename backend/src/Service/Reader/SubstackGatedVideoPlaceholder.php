<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * A paywalled Substack podcast/video post extracts to nothing but dead player
 * chrome ("Playback speed / Share post / 0:00 / Preview") above a short teaser.
 * This runs before readability, on the page document, while the player class
 * and the <head> og tags are still intact: the wrapper-chain collapse strips
 * the `shows-video-player-container` class, so the winning collapsed variant
 * showed nothing to a post-readability pass (#627, #748). It drops the dead
 * player and the paywall landmark and puts a poster image that links to the
 * source article in the player's place.
 */
final readonly class SubstackGatedVideoPlaceholder
{
    private const string PAYWALL = '[aria-label="Paywall"], [data-testid="paywall"]';
    private const string PLAYER = '.shows-video-player-container';
    private const string ARTICLE = 'article.podcast-post, article.shows-post';
    private const string HTTP_URL_PATTERN = '#^https?://#i';

    /** A paragraph this long is prose readability keeps, not chrome or a caption. */
    private const int TEASER_MIN_LENGTH = 80;

    public function replaceIn(HTMLDocument $page): void
    {
        $posterUrl = $this->httpUrlFrom($page, 'meta[property="og:image"]', 'content');
        $sourceUrl = $this->sourceUrl($page);
        if ($posterUrl === null || $sourceUrl === null || !$this->isGatedVideoPost($page)) {
            return;
        }

        $this->replacePlayerWithPoster($page, $sourceUrl, $posterUrl);
    }

    /** og:url, or the canonical link when the page carries no og:url. */
    private function sourceUrl(HTMLDocument $page): ?string
    {
        return $this->httpUrlFrom($page, 'meta[property="og:url"]', 'content')
            ?? $this->httpUrlFrom($page, 'link[rel="canonical"]', 'href');
    }

    private function isGatedVideoPost(HTMLDocument $page): bool
    {
        return $page->querySelector(self::PAYWALL) !== null
            && $page->querySelector(self::PLAYER) !== null
            && $page->querySelector(self::ARTICLE) !== null;
    }

    private function replacePlayerWithPoster(HTMLDocument $page, string $sourceUrl, string $posterUrl): void
    {
        $page->querySelector(self::PAYWALL)?->remove();
        $page->querySelector(self::PLAYER)?->remove();

        // Readability keeps only its chosen content region, which starts at the
        // teaser prose — a poster placed at the top of the article, above the
        // byline card, falls outside it and is dropped. Sit the poster right
        // before the teaser so it lives inside the region readability keeps.
        $teaser = $this->teaserParagraph($page);
        if ($teaser === null || $teaser->parentNode === null) {
            return;
        }

        $teaser->parentNode->insertBefore($this->posterLink($page, $sourceUrl, $posterUrl), $teaser);
    }

    /** The first article paragraph long enough to be prose readability keeps. */
    private function teaserParagraph(HTMLDocument $page): ?Element
    {
        $article = $page->querySelector(self::ARTICLE);
        if ($article === null) {
            return null;
        }

        foreach ($article->getElementsByTagName('p') as $paragraph) {
            if (mb_strlen(trim((string) $paragraph->textContent)) >= self::TEASER_MIN_LENGTH) {
                return $paragraph;
            }
        }

        return null;
    }

    private function posterLink(HTMLDocument $page, string $sourceUrl, string $posterUrl): Element
    {
        $link = $page->createElement('a');
        $link->setAttribute('href', $sourceUrl);

        $image = $page->createElement('img');
        $image->setAttribute('src', $posterUrl);
        $image->setAttribute('alt', 'Video — open the original article to watch');
        $image->setAttribute('width', '1280');
        $image->setAttribute('height', '720');
        $link->appendChild($image);

        return $link;
    }

    /** The attribute's value when it is present and a non-empty http(s) URL. */
    private function httpUrlFrom(HTMLDocument $page, string $selector, string $attribute): ?string
    {
        $value = $page->querySelector($selector)?->getAttribute($attribute);

        return $value !== null && preg_match(self::HTTP_URL_PATTERN, $value) === 1 ? $value : null;
    }
}
