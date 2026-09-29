<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Support\UrlResolver;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\UrlOrigin;

/** The URL context of one page, bound once and asked many times. */
final readonly class PageUrls
{
    public function __construct(private string $pageUrl)
    {
    }

    public function origin(): ?string
    {
        return UrlOrigin::of($this->pageUrl);
    }

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
     * An absolute http(s) URL, or null. A non-http(s) scheme (javascript:, mailto:, data:) is refused before
     * resolving, which would dress it up as a valid-looking https URL.
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

        return AbsoluteHttpUrl::orNull($resolved);
    }

    public function isPageItself(string $url): bool
    {
        return rtrim($url, '/') === rtrim($this->pageUrl, '/');
    }
}
