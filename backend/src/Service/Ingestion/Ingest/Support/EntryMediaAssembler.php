<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Support;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Ingestion\Ingest\Model\AssembledMediaModel;
use App\Service\Ingestion\Parser\Model\ParsedAttachmentModel;
use App\Service\Ingestion\Parser\Model\ParsedMediumModel;
use App\Service\Ingestion\Parser\Model\VisualMediaKind;
use App\Service\Outbound\Image\Model\DeclaredImageModel;

/**
 * Turns the feed's parsed media into the entity's two stored lists. The lead
 * passes the same https-upgrading gate `EntryImageWriter::write` uses, so
 * `media[0]` stays equal to `getImageUrl()` — guarded by EntryIngestorTest.
 */
final class EntryMediaAssembler
{
    /**
     * @param list<ParsedMediumModel>     $media
     * @param list<ParsedAttachmentModel> $attachments
     */
    public static function assemble(?DeclaredImageModel $lead, array $media, array $attachments): AssembledMediaModel
    {
        return new AssembledMediaModel(
            self::visualMedia($lead, $media),
            self::attachments($attachments),
        );
    }

    /**
     * @param list<ParsedMediumModel> $media
     *
     * @return list<EntryMedium>
     */
    public static function assembleVisualMedia(array $media): array
    {
        return self::visualMedia(null, $media);
    }

    /**
     * @param list<ParsedMediumModel> $media
     *
     * @return list<EntryMedium>
     */
    private static function visualMedia(?DeclaredImageModel $lead, array $media): array
    {
        $kept = [];
        $seen = [];

        $leadMedium = self::leadMedium($lead);
        if ($leadMedium !== null) {
            $kept[] = $leadMedium;
            $seen[$leadMedium->url] = true;
        }

        foreach ($media as $medium) {
            $url = HttpsImageUrl::parseUrl($medium->url);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $kept[] = new EntryMedium(
                $url,
                $medium->kind->value,
                $medium->width,
                $medium->height,
                HttpsImageUrl::parseUrl($medium->previewImageUrl),
                $medium->alt,
                $medium->isAnimated,
            );
        }

        return $kept;
    }

    private static function leadMedium(?DeclaredImageModel $lead): ?EntryMedium
    {
        if ($lead === null) {
            return null;
        }
        $url = HttpsImageUrl::parseUrlUpgradingHttp($lead->url);
        if ($url === null) {
            return null;
        }

        return new EntryMedium($url, VisualMediaKind::Image->value, $lead->width, $lead->height);
    }

    /**
     * @param list<ParsedAttachmentModel> $attachments
     *
     * @return list<EntryAttachment>
     */
    private static function attachments(array $attachments): array
    {
        $kept = [];
        foreach ($attachments as $attachment) {
            $url = HttpsImageUrl::parseUrl($attachment->url);
            if ($url === null) {
                continue;
            }
            $kept[] = new EntryAttachment(
                $url,
                $attachment->mimeType,
                $attachment->durationInSeconds,
                $attachment->sizeInBytes,
                $attachment->title,
            );
        }

        return $kept;
    }

    private function __construct()
    {
    }
}
