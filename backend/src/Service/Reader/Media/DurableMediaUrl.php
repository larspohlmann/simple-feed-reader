<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Whether a media URL may enter a body the client caches without a TTL: https, no query, and no shape that belongs
 * to something other than this article. An expiring signed URL would rot into a dead player.
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
