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
        '#/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:[/.]|$)#i';

    /**
     * @param list<string> $ids    every `imageId=` token, lower-cased
     * @param list<string> $tokens the filename stem's distinct, photo-specific words
     */
    private function __construct(
        private string $sourcePath,
        private string $stem,
        private array $ids,
        private array $tokens,
        private ?string $assetToken,
        private ?string $pathUuid,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        $source = ImageProxyUrl::resolve($url);
        $path = (string) (parse_url($source, PHP_URL_PATH) ?? '');
        $stem = self::stripRenderHash(self::stripRenditionSize(
            strtolower((string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($path))),
        ));

        $ids = [];
        if (preg_match_all('/imageid=(\w+)/i', $source, $matches)) {
            $ids = array_map(strtolower(...), $matches[1]);
        }

        $words = preg_split('/[^a-z0-9]+/', $stem, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($words, self::isPhotoSpecificToken(...)));

        return new self($path, $stem, $ids, $tokens, self::assetToken($words), self::pathUuid($path));
    }

    public function isShareRender(): bool
    {
        return preg_match(self::SHARE_RENDER_PATTERN, $this->sourcePath) === 1;
    }

    /**
     * A full UUID (8-4-4-4-12 hex) in the path is the CMS asset id (tagesschau
     * and ARD carry it as a folder, BBC as the filename stem `<uuid>.jpg.webp`):
     * different rendition folders, sizes and extensions around it still name the
     * same photo, and two different UUIDs never do — which stops BBC's shared
     * UUID node field from tying two photos through token matching. A
     * per-rendition transform hash has no such shape and must not be mistaken
     * for one.
     */
    private static function pathUuid(string $path): ?string
    {
        return preg_match(self::UUID_PATH_SEGMENT_PATTERN, $path, $matches) === 1
            ? strtolower($matches[1])
            : null;
    }

    /**
     * ZDF names a rendition with a trailing `~WxH` (`ki-162~384x216`); the same
     * photo at another size differs only there, so it never belongs in the stem.
     * The `~` separator keeps this off the `_`/`-` dimension suffixes #786 must
     * still tell apart as different uploads.
     */
    private static function stripRenditionSize(string $stem): string
    {
        return (string) preg_replace('/~\d+x\d+$/', '', $stem);
    }

    /**
     * heise (#894): a per-rendition hex hash must not survive into the stem.
     * A decimal digit is valid hex too, so only a suffix with a genuine hex
     * letter is stripped — a decimal asset id (timestamp, content id) stays.
     */
    private static function stripRenderHash(string $stem): string
    {
        return (string) preg_replace('/[-_](?=[0-9a-f]*[a-f])[0-9a-f]{12,}$/i', '', $stem);
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
     * The same source file, at any transform or rendition: a shared path UUID, a
     * shared explicit image id, or an identical filename stem, never a shared
     * filename token. Two different stock photos whose filenames share only
     * their library, a batch date and a "download" suffix are therefore kept
     * apart (#1032, Utopia/Pixabay), which the token fallback in isSameAsset()
     * cannot promise. This is the test for a responsive layout that repeats one
     * image, not the broad asset equality.
     */
    private function isSameRendition(self $other): bool
    {
        if ($this->pathUuid !== null && $other->pathUuid !== null) {
            return $this->pathUuid === $other->pathUuid;
        }
        if ($this->ids !== [] && $other->ids !== []) {
            return array_intersect($this->ids, $other->ids) !== [];
        }

        return $this->stem !== '' && $this->stem === $other->stem;
    }

    /**
     * A path UUID, when both sides have one, decides the outcome outright and
     * overrides a stem/id/token match: same UUID is always the same asset,
     * different UUID never is, even when the stem agrees. Beyond that
     * authoritative rendition identity, a shared photo-specific token still
     * counts, so a renamed copy of one photo matches its original.
     */
    public function isSameAsset(self $other): bool
    {
        if ($this->isSameRendition($other)) {
            return true;
        }
        if ($this->pathUuid !== null && $other->pathUuid !== null) {
            return false;
        }
        if ($this->ids !== [] && $other->ids !== []) {
            return false;
        }

        return array_intersect($this->tokens, $other->tokens) !== []
            && !$this->hasDifferentAssetToken($other);
    }

    private function hasDifferentAssetToken(self $other): bool
    {
        return $this->assetToken !== null
            && $other->assetToken !== null
            && $this->assetToken !== $other->assetToken;
    }

    /**
     * A trailing decimal id names one photo among shared words.
     * @param list<string> $words
     */
    private static function assetToken(array $words): ?string
    {
        $last = array_pop($words);
        if (is_string($last) && preg_match('/^\d+x\d+$/', $last) === 1) {
            $last = array_pop($words);
        }

        return is_string($last) && ctype_digit($last) ? $last : null;
    }
}
