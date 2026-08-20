<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Decides whether an article's og:image should lead the reader as a hero.
 *
 * Readability often drops a hero that sits in the page header, outside the
 * scored content, while keeping a *different* photo from the body. The hero is
 * shown unless the extracted body already carries that same image — matched by
 * CDN image identity, so a size or format variant of the one photo counts as a
 * duplicate but a genuinely different picture does not.
 *
 * The candidate is guarded to http(s) so a javascript:/data: URL from the page
 * can never reach the client's <img src>.
 */
final class LeadImageSelector
{
    /** Captures the src of every <img>, whatever quote style the sanitizer used. */
    private const string IMG_SRC = '/<img\b[^>]*?\bsrc\s*=\s*(["\'])(.*?)\1/i';

    public function select(?string $candidate, string $bodyHtml): ?string
    {
        if ($candidate === null || preg_match('#^https?://#i', $candidate) !== 1) {
            return null;
        }

        $candidateIdentity = $this->imageIdentity($candidate);
        $bodyShowsSameImage = array_any(
            $this->bodyImageUrls($bodyHtml),
            fn (string $bodyImageUrl): bool => $this->imageIdentity($bodyImageUrl) === $candidateIdentity,
        );

        return $bodyShowsSameImage ? null : $candidate;
    }

    /** @return list<string> every src the body's <img> tags point at */
    private function bodyImageUrls(string $bodyHtml): array
    {
        preg_match_all(self::IMG_SRC, $bodyHtml, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): string => $match[2], $matches);
    }

    /**
     * A CDN-agnostic identity for an image: the path basename without its
     * extension or query string, lowercased. Size-variant URLs of one photo
     * (`/4943510.jpg?width=1200` vs `/4943510.webp?width=960`) share it; a
     * different photo (`/4943526.jpg`) does not. A URL with no path basename
     * (e.g. `https://cdn.test`) keeps its whole form, so it matches only itself.
     */
    private function imageIdentity(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $basename = is_string($path) ? pathinfo($path, PATHINFO_FILENAME) : '';

        return strtolower($basename !== '' ? $basename : $url);
    }
}
