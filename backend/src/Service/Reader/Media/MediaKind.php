<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

enum MediaKind: string
{
    case Audio = 'audio';
    case Video = 'video';
    /** An HLS playlist: a <video> for Safari and the native client, hls.js elsewhere (#782). */
    case Stream = 'stream';
    case Embed = 'embed';

    /** Plays in a <video> element, so it needs a poster against the TTL-less cache. */
    public function isVideo(): bool
    {
        return $this === self::Video || $this === self::Stream;
    }
}
