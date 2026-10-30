<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\EntryAttachment;
use App\Service\Parser\Model\FeedMediaKind;

/**
 * What a feed media node carries. `<media:thumbnail>` is an image by definition; an explicit `medium` or `type` is
 * authoritative, the URL's extension is the last signal, and a node with none of the three stays Unknown.
 */
final class FeedMediaClassifier
{
    /** @var array<string, FeedMediaKind> file extension => the media it denotes */
    private const array EXTENSION_KINDS = [
        'jpg' => FeedMediaKind::Image, 'jpeg' => FeedMediaKind::Image, 'png' => FeedMediaKind::Image,
        'gif' => FeedMediaKind::Image, 'webp' => FeedMediaKind::Image, 'avif' => FeedMediaKind::Image,
        'bmp' => FeedMediaKind::Image, 'svg' => FeedMediaKind::Image,
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
            ?? self::kindOfUrl($element->getAttribute('url'));
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

        return $type === '' ? null : self::kindOfMimeType($type);
    }

    public static function kindOfMimeType(string $mimeType): FeedMediaKind
    {
        $type = strtolower($mimeType);

        return match (true) {
            str_starts_with($type, 'image/') => FeedMediaKind::Image,
            str_starts_with($type, 'audio/') => FeedMediaKind::Audio,
            str_starts_with($type, 'video/') => FeedMediaKind::Video,
            default => FeedMediaKind::Other,
        };
    }

    public static function kindOfUrl(string $url): FeedMediaKind
    {
        $extension = EntryAttachment::extensionOf($url);
        if (\in_array($extension, EntryAttachment::AUDIO_EXTENSIONS, true)) {
            return FeedMediaKind::Audio;
        }

        return self::EXTENSION_KINDS[$extension] ?? FeedMediaKind::Unknown;
    }

    private function __construct()
    {
    }
}
