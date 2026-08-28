<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * A deliberately light fingerprint of an image URL, used to answer one question:
 * do two URLs point at the same photo? It reads the filename stem, any
 * `imageId=` token, and the stem's distinct words — and ignores the host, the
 * directory, the extension and the size/format query.
 *
 * It is light on purpose. The reader once carried per-CDN URL normalisation to
 * collapse crop and size variants (#520/#590/#592/#608/#610/#619/#625); that
 * whack-a-mole was deleted in #657. This does NOT bring it back. Two renders of
 * one photo that share no distinct filename token — a Drupal share-render beside
 * the original upload (beat.de), or two generic crop names under one image-group
 * directory (zeit) — are reported as different. That miss is safe by design: the
 * one caller (ReaderLeadImage) only ever skips restoring a picture on a miss, so
 * the cost is today's behaviour, never a duplicated photo.
 *
 * One transform runs before the fingerprint: a proxying image CDN is unwrapped to
 * the source URL it carries (#686), so the direct og:image and its proxied copy
 * on the page read as the same photo. A host-only proxy (Jetpack Photon,
 * Cloudinary fetch) already keeps the filename, so only the spellings that hide
 * it need this: imgproxy's base64-in-the-path and a `?url=` query parameter.
 */
final readonly class ImageIdentity
{
    /**
     * Filename words that name a photo library or a boilerplate role, not the
     * photo. Two unrelated Getty pictures share `gettyimages`, two unrelated
     * crops share `image`; matching on those fabricates identity, and a false
     * match is the one direction that can duplicate a picture — so the list
     * leans complete, and over-listing only ever costs a match, which is safe.
     *
     * A structural rule was tried instead of a list — a token counts only if it
     * carries a digit, so numeric ids and hashes qualify but generic words do
     * not — and measured over the whole feed corpus (#686): it dropped ~40 real
     * matches, below even the pre-#686 recall. What it discarded is the ordinary
     * WordPress size variant: `vegane-burrata-002.jpg` beside
     * `vegane-burrata-002-1280x854.jpg`, `BruceSpringsteen-1.jpg` beside
     * `brucespringsteen-1.jpg` — whose only tie is a descriptive, all-letters
     * filename. A generic word and a distinctive one are both plain text; only a
     * word list tells them apart, so the list stays.
     */
    private const array GENERIC_TOKENS = [
        'image', 'images', 'photo', 'photos', 'picture', 'pictures', 'photograph',
        'thumbnail', 'thumb', 'default', 'featured', 'header', 'hero', 'cover',
        'banner', 'screenshot', 'original', 'final', 'output', 'upload', 'uploads',
        'getty', 'gettyimages', 'istock', 'istockphoto', 'shutterstock',
        'unsplash', 'pexels', 'adobestock',
    ];

    /**
     * @param list<string> $ids    every `imageId=` token, lower-cased
     * @param list<string> $tokens the filename stem's distinct, photo-specific words
     */
    private function __construct(
        private string $stem,
        private array $ids,
        private array $tokens,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        $source = self::unwrapProxy($url);
        $path = (string) (parse_url($source, PHP_URL_PATH) ?? '');
        $stem = strtolower((string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($path)));

        $ids = [];
        if (preg_match_all('/imageid=(\w+)/i', $source, $matches)) {
            $ids = array_map(strtolower(...), $matches[1]);
        }

        $words = preg_split('/[^a-z0-9]+/', $stem, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($words, self::isPhotoSpecificToken(...)));

        return new self($stem, $ids, $tokens);
    }

    private static function isPhotoSpecificToken(string $word): bool
    {
        return strlen($word) >= 5 && !in_array($word, self::GENERIC_TOKENS, true);
    }

    public function matches(self $other): bool
    {
        if ($this->stem !== '' && $this->stem === $other->stem) {
            return true;
        }

        return array_intersect($this->ids, $other->ids) !== []
            || array_intersect($this->tokens, $other->tokens) !== [];
    }

    /**
     * The real source URL a proxying CDN embeds, or the URL unchanged when it is
     * not a wrapper. Only an http(s) result is trusted; anything else is not a
     * proxy and the original stands, so a miss keeps the fingerprint as it was.
     */
    private static function unwrapProxy(string $url): string
    {
        return self::sourceFromQuery($url)
            ?? self::sourceFromPath($url)
            ?? $url;
    }

    /** A `?url=` proxy (Politico's dims4, NPR's brightspot) carries the source verbatim. */
    private static function sourceFromQuery(string $url): ?string
    {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return null;
        }

        parse_str($query, $parameters);
        $embedded = $parameters['url'] ?? null;

        return is_string($embedded) && self::isHttpUrl($embedded) ? $embedded : null;
    }

    /**
     * imgproxy carries the source as the final path segment, url-safe base64.
     * Strict decoding rejects a real filename on its own (it is not a base64 http
     * URL) and tolerates the unpadded spelling once `-_` map back to `+/`.
     */
    private static function sourceFromPath(string $url): ?string
    {
        $segment = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $candidate = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $segment);
        $decoded = base64_decode(strtr($candidate, '-_', '+/'), true);

        return $decoded !== false && self::isHttpUrl($decoded) ? $decoded : null;
    }

    private static function isHttpUrl(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1;
    }
}
