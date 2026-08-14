<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;

/**
 * The URL context of one page, bound once and asked many times.
 *
 * Everything that reads a page — the scraper layers, feed discovery, the
 * favicon resolver — resolves the references it finds against that page. The
 * page URL is per-pass state, so it lives here rather than travelling as a
 * parameter through every private helper down to the resolution itself.
 *
 * UrlResolver stays the algorithm and keeps its one-shot callers: a Location
 * header resolved against the URL that produced it has a base, but no page.
 */
final readonly class PageUrls
{
    public function __construct(private string $pageUrl)
    {
    }

    /** Scheme, host and port of the page, or null when it names no host. */
    public function origin(): ?string
    {
        return UrlResolver::origin($this->pageUrl);
    }

    /** The page's own path, always leading-slashed: "/" when it carries none. */
    public function path(): string
    {
        $path = parse_url($this->pageUrl, \PHP_URL_PATH);

        return \is_string($path) && '' !== $path ? $path : '/';
    }

    /** @throws FeedUnreachableException when the page names no host to resolve against */
    public function resolve(string $reference): string
    {
        return UrlResolver::resolve($this->pageUrl, $reference);
    }

    /**
     * Absolute http(s) URL of a reference found on the page, or null when it
     * names nothing fetchable.
     *
     * Empty references and non-http(s) schemes (javascript:, mailto:, data:, …)
     * are rejected up front — resolving such a scheme against the page would
     * otherwise produce a syntactically valid-looking https URL.
     */
    public function httpUrl(?string $reference): ?string
    {
        $reference = trim($reference ?? '');
        if ('' === $reference || 1 === preg_match('#^(?!https?://)[a-z][a-z0-9+.-]*:#i', $reference)) {
            return null;
        }

        try {
            $resolved = $this->resolve($reference);
        } catch (FeedUnreachableException) {
            return null;
        }

        return 1 === preg_match('#^https?://#i', $resolved) ? $resolved : null;
    }

    /** Whether a resolved URL leads back to the page itself, trailing slash aside. */
    public function isPageItself(string $url): bool
    {
        return rtrim($url, '/') === rtrim($this->pageUrl, '/');
    }
}
