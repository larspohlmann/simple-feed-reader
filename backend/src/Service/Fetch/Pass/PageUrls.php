<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Support\UrlResolver;
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

    /** An absolute http(s) URL, or null. */
    public function httpUrl(?string $reference): ?string
    {
        $reference = trim($reference ?? '');
        if ('' === $reference) {
            return null;
        }

        try {
            return $this->resolve($reference);
        } catch (FeedUnreachableException) {
            return null;
        }
    }

    public function isPageItself(string $url): bool
    {
        return rtrim($url, '/') === rtrim($this->pageUrl, '/');
    }
}
