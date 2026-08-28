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
 */
final readonly class ImageIdentity
{
    /**
     * @param list<string> $ids    every `imageId=` token, lower-cased
     * @param list<string> $tokens the filename stem's distinct words (>= 5 chars)
     */
    private function __construct(
        private string $stem,
        private array $ids,
        private array $tokens,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $stem = strtolower((string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($path)));

        $ids = [];
        if (preg_match_all('/imageid=(\w+)/i', $url, $matches)) {
            $ids = array_map(strtolower(...), $matches[1]);
        }

        $words = preg_split('/[^a-z0-9]+/', $stem, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($words, static fn (string $w): bool => strlen($w) >= 5));

        return new self($stem, $ids, $tokens);
    }

    public function matches(self $other): bool
    {
        if ($this->stem !== '' && $this->stem === $other->stem) {
            return true;
        }

        return array_intersect($this->ids, $other->ids) !== []
            || array_intersect($this->tokens, $other->tokens) !== [];
    }
}
