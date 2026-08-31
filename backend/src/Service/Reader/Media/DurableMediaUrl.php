<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Decides whether a media URL is safe to write into a body the client caches
 * without a TTL. A signed URL that expires would rot into a dead player, so the
 * bar is deliberately high: https, no query at all, and none of the shapes that
 * are technically reachable but belong to something other than this article.
 *
 * Adapters strip known analytics parameters before the guard runs. Whatever
 * query survives that is unexplained, so it is refused rather than guessed at.
 */
final readonly class DurableMediaUrl
{
    /** Machine narration of the article the reader is already showing. */
    private const string NARRATION_PATTERN = '#/tts/|Neural\.mp3$#i';

    /** A station stream is not this episode. */
    private const string LIVE_PATTERN = '#(^|\.)sslstream\.|/live/|/stream\.mp3$#i';

    public function accepts(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['query'])) {
            return false;
        }

        return !$this->isExcluded($parts['host'] . $parts['path']);
    }

    private function isExcluded(string $hostAndPath): bool
    {
        return preg_match(self::NARRATION_PATTERN, $hostAndPath) === 1
            || preg_match(self::LIVE_PATTERN, $hostAndPath) === 1;
    }
}
