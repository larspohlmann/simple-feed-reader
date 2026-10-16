<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\DeclaredImages;
use App\Service\Url\Support\HttpsImageUrl;

final class BlueskyEmbedMedia
{
    private const string ALT_PREFIX = 'ALT:';

    /** @return list<ParsedMediumModel> */
    public static function images(JsonNodeModel $embed): array
    {
        $media = [];
        foreach ($embed->nodes('images') as $image) {
            $medium = self::image($image);
            if ($medium !== null) {
                $media[] = $medium;
            }
        }

        return $media;
    }

    public static function image(JsonNodeModel $image): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($image->string('fullsize'));
        if ($url === null) {
            return null;
        }
        $ratio = $image->node('aspectRatio');

        return new ParsedMediumModel($url, VisualMediaKind::Image, $ratio->int('width'), $ratio->int('height'));
    }

    public static function video(JsonNodeModel $embed): ?ParsedMediumModel
    {
        $playlist = HttpsImageUrl::orNull($embed->string('playlist'));
        if ($playlist === null) {
            return null;
        }
        $ratio = $embed->node('aspectRatio');

        return new ParsedMediumModel(
            $playlist,
            VisualMediaKind::Video,
            $ratio->int('width'),
            $ratio->int('height'),
            HttpsImageUrl::orNull($embed->string('thumbnail')),
        );
    }

    public static function gif(JsonNodeModel $external): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($external->string('uri'));
        if ($url === null || !self::isGifPath($url)) {
            return null;
        }
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $width = self::dimensionOf($query['ww'] ?? null);
        $height = self::dimensionOf($query['hh'] ?? null);
        if ($width === null || $height === null) {
            return null;
        }

        return new ParsedMediumModel(
            $url,
            VisualMediaKind::Image,
            $width,
            $height,
            HttpsImageUrl::orNull($external->string('thumb')),
            self::gifAltText($external),
            isAnimated: true,
        );
    }

    private static function gifAltText(JsonNodeModel $external): ?string
    {
        $description = $external->string('description') ?? '';
        $alt = str_starts_with($description, self::ALT_PREFIX)
            ? trim(substr($description, \strlen(self::ALT_PREFIX)))
            : $description;

        return $alt === '' ? $external->string('title') : $alt;
    }

    private static function isGifPath(string $url): bool
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return \is_string($path) && str_ends_with(strtolower($path), '.gif');
    }

    private static function dimensionOf(mixed $value): ?int
    {
        return \is_string($value) ? DeclaredImages::positiveDimension($value) : null;
    }

    private function __construct()
    {
    }
}
