<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Decides what a feed media node — `<media:content>`, `<media:thumbnail>`, or an
 * `<enclosure>` — carries. `<media:thumbnail>` is an image by definition. An
 * explicit `medium` or `type` is authoritative; when both are absent the URL's
 * file extension is the only signal left. A node that declares none of the three
 * stays Unknown so the caller can leave it out rather than mis-file it.
 */
final class FeedMediaClassifier
{
    /** @var array<string, FeedMediaKind> file extension => the media it denotes */
    private const array EXTENSION_KINDS = [
        'jpg' => FeedMediaKind::Image, 'jpeg' => FeedMediaKind::Image, 'png' => FeedMediaKind::Image,
        'gif' => FeedMediaKind::Image, 'webp' => FeedMediaKind::Image, 'avif' => FeedMediaKind::Image,
        'bmp' => FeedMediaKind::Image, 'svg' => FeedMediaKind::Image,
        'mp3' => FeedMediaKind::Audio, 'm4a' => FeedMediaKind::Audio, 'aac' => FeedMediaKind::Audio,
        'ogg' => FeedMediaKind::Audio, 'oga' => FeedMediaKind::Audio, 'opus' => FeedMediaKind::Audio,
        'wav' => FeedMediaKind::Audio, 'flac' => FeedMediaKind::Audio, 'wma' => FeedMediaKind::Audio,
        'mp4' => FeedMediaKind::Video, 'm4v' => FeedMediaKind::Video, 'mov' => FeedMediaKind::Video,
        'webm' => FeedMediaKind::Video, 'mkv' => FeedMediaKind::Video, 'avi' => FeedMediaKind::Video,
        'ogv' => FeedMediaKind::Video,
    ];

    public static function kind(\DOMElement $element): FeedMediaKind
    {
        if ($element->localName === 'thumbnail') {
            return FeedMediaKind::Image;
        }

        return self::fromMedium($element)
            ?? self::fromType($element)
            ?? self::fromExtension($element->getAttribute('url'));
    }

    private static function fromMedium(\DOMElement $element): ?FeedMediaKind
    {
        return match (strtolower($element->getAttribute('medium'))) {
            'image' => FeedMediaKind::Image,
            'audio' => FeedMediaKind::Audio,
            'video' => FeedMediaKind::Video,
            '' => null,
            default => FeedMediaKind::Other,
        };
    }

    private static function fromType(\DOMElement $element): ?FeedMediaKind
    {
        $type = strtolower($element->getAttribute('type'));
        if ($type === '') {
            return null;
        }

        return match (true) {
            str_starts_with($type, 'image/') => FeedMediaKind::Image,
            str_starts_with($type, 'audio/') => FeedMediaKind::Audio,
            str_starts_with($type, 'video/') => FeedMediaKind::Video,
            default => FeedMediaKind::Other,
        };
    }

    private static function fromExtension(string $url): FeedMediaKind
    {
        $path = strtolower((string) parse_url(trim($url), \PHP_URL_PATH));
        $extension = pathinfo($path, \PATHINFO_EXTENSION);

        return self::EXTENSION_KINDS[$extension] ?? FeedMediaKind::Unknown;
    }
}
