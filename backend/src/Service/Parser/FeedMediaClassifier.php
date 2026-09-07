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
    /** @var list<string> */
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'];

    /** @var list<string> */
    private const array AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'wma'];

    /** @var list<string> */
    private const array VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'ogv'];

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

        foreach (self::extensionTable() as [$extensions, $kind]) {
            foreach ($extensions as $extension) {
                if (str_ends_with($path, '.' . $extension)) {
                    return $kind;
                }
            }
        }

        return FeedMediaKind::Unknown;
    }

    /** @return list<array{list<string>, FeedMediaKind}> */
    private static function extensionTable(): array
    {
        return [
            [self::IMAGE_EXTENSIONS, FeedMediaKind::Image],
            [self::AUDIO_EXTENSIONS, FeedMediaKind::Audio],
            [self::VIDEO_EXTENSIONS, FeedMediaKind::Video],
        ];
    }
}
