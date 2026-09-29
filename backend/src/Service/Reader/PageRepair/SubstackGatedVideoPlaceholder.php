<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Url\Support\AbsoluteHttpUrl;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Replaces a paywalled Substack video post's dead player and paywall landmark with a poster linking to the article.
 * It runs as a page repair, before the wrapper-chain collapse removes the player class it keys on.
 */
final readonly class SubstackGatedVideoPlaceholder implements PageRepairInterface
{
    private const string PAYWALL = '[aria-label="Paywall"], [data-testid="paywall"]';
    private const string PLAYER = '.shows-video-player-container';
    private const string ARTICLE = 'article.podcast-post, article.shows-post';

    /** A paragraph this long is prose readability keeps, not chrome or a caption. */
    private const int TEASER_MIN_LENGTH = 80;

    public function repairIn(HTMLDocument $page): void
    {
        $posterUrl = $this->httpUrlFrom($page, 'meta[property="og:image"]', 'content');
        $sourceUrl = $this->sourceUrl($page);
        if ($posterUrl === null || $sourceUrl === null || !$this->isGatedVideoPost($page)) {
            return;
        }

        $this->replacePlayerWithPoster($page, $sourceUrl, $posterUrl);
    }

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

        // Readability keeps only content adjacent to the teaser prose; a poster
        // at the top of the article, above the byline card, falls outside that
        // region and is dropped. Sit it right before the teaser so it survives.
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
        // The sanitizer strips class/style, so this alt is the only hook the
        // reader's play-button overlay has (reader-view.component.scss). Keep the
        // two in lockstep — a reword here silently drops the overlay.
        $image->setAttribute('alt', 'Video — open the original article to watch');
        $image->setAttribute('width', '1280');
        $image->setAttribute('height', '720');
        $link->appendChild($image);

        return $link;
    }

    private function httpUrlFrom(HTMLDocument $page, string $selector, string $attribute): ?string
    {
        return AbsoluteHttpUrl::orNull($page->querySelector($selector)?->getAttribute($attribute));
    }
}
