<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Url\Support\HttpsImageUrl;

final class BlueskyEmbedMedia
{
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

    private function __construct()
    {
    }
}
