<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\Media\Support\LiveAudioStream;

/**
 * Whether a media URL may enter a body the client caches without a TTL: https, no query, and no shape that belongs
 * to something other than this article (narration, a live stream). An expiring signed URL would rot into a dead player.
 */
final readonly class DurableMediaUrl
{
    /** Machine narration of the article the reader is already showing. */
    private const string NARRATION_PATTERN = '#/tts/|Neural\.mp3$#i';

    public function accepts(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['query'])) {
            return false;
        }

        return preg_match(self::NARRATION_PATTERN, $parts['path']) !== 1 && !LiveAudioStream::is($url);
    }
}
