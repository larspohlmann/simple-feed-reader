<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Entity\EntryMedium;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Ingestion\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingestion\Parser\Model\ParsedMediumModel;
use App\Service\Ingestion\Parser\Model\VisualMediaKind;

/** A status's `media_attachments` as pictures and videos; audio and unknown files are left out. */
final class MastodonAttachments
{
    /**
     * @param list<JsonNodeModel> $attachments
     *
     * @return list<ParsedMediumModel>
     */
    public static function parseMedia(array $attachments): array
    {
        return array_values(array_filter(array_map(self::parseMedium(...), $attachments)));
    }

    /** @return list<EntryMedium> */
    public static function assembleEntryMedia(JsonNodeModel $status): array
    {
        return EntryMediaAssembler::assembleVisualMedia(self::parseMedia($status->nodes('media_attachments')));
    }

    private static function parseMedium(JsonNodeModel $attachment): ?ParsedMediumModel
    {
        $url = HttpsImageUrl::orNull($attachment->string('url'));
        $size = $attachment->node('meta')->node('original');
        $type = $attachment->string('type') ?? '';

        return match (true) {
            null === $url => null,
            'image' === $type => new ParsedMediumModel(
                $url,
                VisualMediaKind::Image,
                $size->int('width'),
                $size->int('height'),
                alt: $attachment->string('description'),
            ),
            'video' === $type, 'gifv' === $type => new ParsedMediumModel(
                $url,
                VisualMediaKind::Video,
                $size->int('width'),
                $size->int('height'),
                HttpsImageUrl::orNull($attachment->string('preview_url')),
                $attachment->string('description'),
                isAnimated: 'gifv' === $type,
            ),
            default => null,
        };
    }

    private function __construct()
    {
    }
}
