<?php

declare(strict_types=1);

namespace App\Service\Reader;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Decides whether an `<a>` is a share control. Two shapes count: a link to a
 * known share endpoint (a sharer/intent URL, or `mailto:`) whose query carries
 * an address, and a link back to the page's own address that carries a share
 * action — Substack's Share button (`action=share`) and WordPress's sharing
 * links (`?share=…`), which no endpoint list could name (#627, #786).
 *
 * A share-host link carrying no page URL — POLITICO's WhatsApp "message the
 * hosts" contact link motivated the distinction — is editorial, not a
 * control, and stays; so does a self link without a share action (a
 * permalink, a comments anchor). The page's own address is its canonical
 * link, else its og:url; a page declaring neither has no self links to judge.
 */
final readonly class ShareLinkMatcher
{
    /**
     * Host (minus `www.`) plus path prefixes that identify a share endpoint.
     * `mailto:` is checked separately — it carries no host at all.
     */
    private const array SHARE_ENDPOINTS = [
        'facebook.com/sharer', 'facebook.com/share.php', 'facebook.com/dialog/',
        'x.com/intent', 'twitter.com/intent', 'bsky.app/intent', 'threads.net/intent',
        'linkedin.com/sharearticle', 'linkedin.com/sharing/share-offsite',
        'reddit.com/submit', 'pinterest.com/pin/create', 'tumblr.com/share',
        'tumblr.com/widgets/share', 'vk.com/share.php', 'xing.com/spi/shares/new',
        'getpocket.com/edit', 'getpocket.com/save', 'flipboard.com/bookmarklet/popup',
        'api.whatsapp.com/send', 'wa.me/', 't.me/share', 'telegram.me/share',
    ];

    /** A query value that is itself an address — http(s), plain or percent-encoded. */
    private const string SHARED_ADDRESS_PATTERN = '#https?(://|%3a%2f%2f)#i';

    private function __construct(private ?string $ownAddress)
    {
    }

    public static function forPage(HTMLDocument $document): self
    {
        return new self(self::declaredAddress($document));
    }

    public function matches(Element $link): bool
    {
        $href = $link->getAttribute('href');
        if ($href === null) {
            return false;
        }

        return $this->isShareIntent($href) || $this->isSelfShareAction($href);
    }

    private function isShareIntent(string $href): bool
    {
        return $this->pointsAtAShareEndpoint($href) && $this->carriesTheSharedAddress($href);
    }

    private function isSelfShareAction(string $href): bool
    {
        return $this->ownAddress !== null
            && self::address($href) === $this->ownAddress
            && self::carriesAShareAction($href);
    }

    private static function carriesAShareAction(string $href): bool
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $parameters);

        return isset($parameters['share']) || ($parameters['action'] ?? null) === 'share';
    }

    private static function declaredAddress(HTMLDocument $document): ?string
    {
        $declared = $document->querySelector('link[rel="canonical"]')?->getAttribute('href')
            ?? $document->querySelector('meta[property="og:url"]')?->getAttribute('content');

        return $declared === null || trim($declared) === '' ? null : self::address($declared);
    }

    private function pointsAtAShareEndpoint(string $href): bool
    {
        if (str_starts_with($href, 'mailto:')) {
            return true;
        }

        $hostAndPath = self::hostAndPath($href);

        return array_any(
            self::SHARE_ENDPOINTS,
            fn (string $endpoint): bool => $this->matchesEndpointBoundary($hostAndPath, $endpoint),
        );
    }

    /**
     * A prefix match alone lets `reddit.com/submit-guidelines` match the
     * `reddit.com/submit` endpoint. The path must end there — the next
     * character is a separator, a file extension's dot (`facebook.com/sharer.php`,
     * 5 Magazine and Nature's shape), or the string ends — so a longer,
     * unrelated path segment is rejected.
     */
    private function matchesEndpointBoundary(string $hostAndPath, string $endpoint): bool
    {
        $endpoint = rtrim($endpoint, '/');
        $hasPathSegment = str_contains($endpoint, '/');

        return $hostAndPath === $endpoint
            || str_starts_with($hostAndPath, $endpoint . '/')
            || ($hasPathSegment && str_starts_with($hostAndPath, $endpoint . '.'));
    }

    /** The address a self link is compared by: host and path, without a trailing slash. */
    private static function address(string $url): string
    {
        return rtrim(self::hostAndPath($url), '/');
    }

    /** Lowercased throughout — LinkedIn's own share link uses `/shareArticle`. */
    private static function hostAndPath(string $href): string
    {
        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = strtolower((string) parse_url($href, PHP_URL_PATH));

        return $host . $path;
    }

    private function carriesTheSharedAddress(string $href): bool
    {
        $queryStart = strpos($href, '?');
        if ($queryStart === false) {
            return false;
        }

        return preg_match(self::SHARED_ADDRESS_PATTERN, substr($href, $queryStart + 1)) === 1;
    }
}
