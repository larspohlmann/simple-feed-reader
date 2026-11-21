<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Parser\Support;

use App\Entity\PlayableMediaFile;
use App\Service\Ingestion\Parser\Model\FeedMediaKind;

/**
 * What a feed media node carries. `<media:thumbnail>` is an image by definition; an explicit `medium` or `type` is
 * authoritative, the URL's extension is the last signal, and a node with none of the three stays Unknown.
 */
final class FeedMediaClassifier
{
    private const array IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg'];

    private const array UNPLAYABLE_MEDIA_EXTENSIONS = ['wma', 'mkv', 'avi', 'ogv'];

    public static function kind(\DOMElement $element): FeedMediaKind
    {
        if ($element->localName === 'thumbnail') {
            return FeedMediaKind::Image;
        }

        return self::fromMedium($element)
            ?? self::fromType($element)
            ?? self::classifyUrl($element->getAttribute('url'));
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
        $type = $element->getAttribute('type');

        return $type === '' ? null : self::classifyMimeType($type);
    }

    public static function classifyMimeType(string $mimeType): FeedMediaKind
    {
        $type = strtolower($mimeType);

        return match (true) {
            str_starts_with($type, 'image/') => FeedMediaKind::Image,
            str_starts_with($type, 'audio/') => FeedMediaKind::Audio,
            str_starts_with($type, 'video/') => FeedMediaKind::Video,
            default => FeedMediaKind::Other,
        };
    }

    public static function classifyUrl(string $url): FeedMediaKind
    {
        $extension = PlayableMediaFile::extractExtension($url);
        if (PlayableMediaFile::isAudioExtension($extension)) {
            return FeedMediaKind::Audio;
        }
        if (PlayableMediaFile::isVideoExtension($extension)) {
            return FeedMediaKind::Video;
        }
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return FeedMediaKind::Image;
        }

        return in_array($extension, self::UNPLAYABLE_MEDIA_EXTENSIONS, true)
            ? FeedMediaKind::Other
            : FeedMediaKind::Unknown;
    }

    private function __construct()
    {
    }
}
