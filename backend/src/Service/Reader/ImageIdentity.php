<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** Fingerprints image URLs for broad rendition matching and conservative asset equality. */
final readonly class ImageIdentity
{
    /** Filename tokens that name libraries or generic roles, not individual photos. */
    private const array GENERIC_TOKENS = [
        'image', 'images', 'photo', 'photos', 'picture', 'pictures', 'photograph',
        'thumbnail', 'thumb', 'default', 'featured', 'header', 'hero', 'cover',
        'banner', 'screenshot', 'original', 'final', 'output', 'upload', 'uploads',
        'getty', 'gettyimages', 'istock', 'istockphoto', 'shutterstock',
        'unsplash', 'pexels', 'adobestock',
    ];

    /**
     * Pictures rendered for link previews, never drawn for readers: a
     * subscribe/share card file, a preview directory, an "og image" (#786;
     * measured on Substack, trance-nexus and stitcher.io).
     */
    private const string SHARE_RENDER_PATTERN =
        '#/(?:og|opengraph|meta)/|(?:subscribe|share|social|twitter|og)[-_]card\.[a-z0-9]{2,5}$'
        . '|(?:^|/)(?:og|opengraph)[-_]?image\.[a-z0-9]{2,5}$#i';

    private const string UUID_PATH_SEGMENT_PATTERN =
        '#/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:/|$)#i';

    /**
     * @param list<string> $ids    every `imageId=` token, lower-cased
     * @param list<string> $tokens the filename stem's distinct, photo-specific words
     */
    private function __construct(
        private string $sourcePath,
        private string $stem,
        private array $ids,
        private array $tokens,
        private ?string $numericAsset,
        private ?string $pathUuid,
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

        return new self($path, $stem, $ids, $tokens, self::numericAsset($words), self::pathUuid($path));
    }

    public function isShareRender(): bool
    {
        return preg_match(self::SHARE_RENDER_PATTERN, $this->sourcePath) === 1;
    }

    /**
     * A path segment shaped like a full UUID (8-4-4-4-12 hex) is the CMS asset
     * id (tagesschau, ARD): different rendition folders and filenames around
     * it still name the same photo. A per-rendition transform hash has no such
     * shape and must not be mistaken for one.
     */
    private static function pathUuid(string $path): ?string
    {
        return preg_match(self::UUID_PATH_SEGMENT_PATTERN, $path, $matches) === 1
            ? strtolower($matches[1])
            : null;
    }

    /** A `WxH` word is a rendition size, never a photo. */
    private static function isPhotoSpecificToken(string $word): bool
    {
        return strlen($word) >= 5
            && !in_array($word, self::GENERIC_TOKENS, true)
            && preg_match('/^\d+x\d+$/', $word) !== 1;
    }

    /**
     * A differing path UUID overrides an identical stem here too, not just in
     * isSameAsset(); the only effect is a skipped ReaderLeadImage::restore(),
     * never an inserted wrong image.
     */
    public function matches(self $other): bool
    {
        if ($this->pathUuid !== null && $other->pathUuid !== null) {
            return $this->pathUuid === $other->pathUuid;
        }
        if ($this->stem !== '' && $this->stem === $other->stem) {
            return true;
        }

        return array_intersect($this->ids, $other->ids) !== []
            || array_intersect($this->tokens, $other->tokens) !== [];
    }

    /**
     * A path UUID, when both sides have one, decides the outcome outright and
     * overrides a stem/id/token match: same UUID is always the same asset,
     * different UUID never is, even when the stem agrees.
     */
    public function isSameAsset(self $other): bool
    {
        if ($this->pathUuid !== null && $other->pathUuid !== null) {
            return $this->pathUuid === $other->pathUuid;
        }
        if ($this->ids !== [] && $other->ids !== []) {
            return array_intersect($this->ids, $other->ids) !== [];
        }
        if ($this->stem !== '' && $this->stem === $other->stem) {
            return true;
        }

        return array_intersect($this->tokens, $other->tokens) !== []
            && !$this->hasDifferentNumericAsset($other);
    }

    private function hasDifferentNumericAsset(self $other): bool
    {
        return $this->numericAsset !== null
            && $other->numericAsset !== null
            && $this->numericAsset !== $other->numericAsset;
    }

    /** @param list<string> $words */
    private static function numericAsset(array $words): ?string
    {
        $last = array_pop($words);
        if (is_string($last) && preg_match('/^\d+x\d+$/', $last) === 1) {
            $last = array_pop($words);
        }

        return is_string($last) && ctype_digit($last) ? $last : null;
    }

    /** Return an embedded HTTP source URL, or keep the original URL. */
    private static function unwrapProxy(string $url): string
    {
        return self::sourceFromQuery($url)
            ?? self::sourceFromPath($url)
            ?? self::sourceFromEncodedPath($url)
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

    /** Decode imgproxy's URL-safe base64 source in the final path segment. */
    private static function sourceFromPath(string $url): ?string
    {
        $segment = basename((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $candidate = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', $segment);
        $decoded = base64_decode(strtr($candidate, '-_', '+/'), true);

        return $decoded !== false && self::isHttpUrl($decoded) ? $decoded : null;
    }

    /** A percent-encoded source as the final path segment (Substack's `/image/fetch/<transforms>/<source>`). */
    private static function sourceFromEncodedPath(string $url): ?string
    {
        $decoded = rawurldecode(basename((string) (parse_url($url, PHP_URL_PATH) ?? '')));

        return self::isHttpUrl($decoded) ? $decoded : null;
    }

    private static function isHttpUrl(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1;
    }
}
