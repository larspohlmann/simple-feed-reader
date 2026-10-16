<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\EntryMedium;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Url\Support\HttpsImageUrl;

/** A status's `media_attachments` as pictures and videos; audio and unknown files are left out. */
final class MastodonAttachments
{
    private const array VIDEO_TYPES = ['video', 'gifv'];

    /**
     * @param list<JsonNodeModel> $attachments
     *
     * @return list<ParsedMediumModel>
     */
    public static function of(array $attachments): array
    {
        return array_values(array_filter(array_map(self::mediumOf(...), $attachments)));
    }

    /** @return list<EntryMedium> */
    public static function entryMediaOf(JsonNodeModel $status): array
    {
        return EntryMediaAssembler::mediaOf(self::of($status->nodes('media_attachments')));
    }

    private static function mediumOf(JsonNodeModel $attachment): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($attachment->string('url'));
        $size = $attachment->node('meta')->node('original');
        $type = $attachment->string('type');

        return match (true) {
            null === $url => null,
            'image' === $type => new ParsedMediumModel(
                $url,
                VisualMediaKind::Image,
                $size->int('width'),
                $size->int('height'),
            ),
            \in_array($type, self::VIDEO_TYPES, true) => new ParsedMediumModel(
                $url,
                VisualMediaKind::Video,
                $size->int('width'),
                $size->int('height'),
                HttpsImageUrl::orNull($attachment->string('preview_url')),
            ),
            default => null,
        };
    }

    private function __construct()
    {
    }
}
