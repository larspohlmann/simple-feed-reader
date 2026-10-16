<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Url\Support\HttpsImageUrl;

final class BlueskyEmbedMedia
{
    private const string ALT_PREFIX = 'ALT: ';

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

    /** A GIF picker's external embed links the GIF itself and states its size as `ww` and `hh` in the query. */
    public static function gif(JsonNodeModel $external): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($external->string('uri'));
        if ($url === null || !self::isHttpsGif($url)) {
            return null;
        }
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $width = self::positiveInt($query['ww'] ?? null);
        $height = self::positiveInt($query['hh'] ?? null);

        return $width === null || $height === null
            ? null
            : new ParsedMediumModel($url, VisualMediaKind::Image, $width, $height);
    }

    public static function gifAltText(JsonNodeModel $external): string
    {
        $description = $external->string('description');
        if ($description === null) {
            return $external->string('title') ?? '';
        }

        return str_starts_with($description, self::ALT_PREFIX)
            ? substr($description, \strlen(self::ALT_PREFIX))
            : $description;
    }

    private static function isHttpsGif(string $url): bool
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return str_starts_with(strtolower($url), 'https://')
            && \is_string($path)
            && str_ends_with(strtolower($path), '.gif');
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!\is_string($value)) {
            return null;
        }
        $number = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $number === false ? null : $number;
    }

    private function __construct()
    {
    }
}
