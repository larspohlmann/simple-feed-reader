<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Service\Image\DeclaredImage;
use App\Service\Parser\ParsedAttachment;
use App\Service\Parser\ParsedMedium;
use App\Service\Parser\VisualMediaKind;
use App\Service\Url\HttpsImageUrl;

/**
 * Turns the feed's parsed media into the entity's two stored lists. The lead
 * passes the same https-upgrading gate `EntryIngestor::storeImage` uses, so
 * `media[0]` stays equal to `getImageUrl()` — guarded by EntryIngestorTest.
 */
final class EntryMediaAssembler
{
    /**
     * @param list<ParsedMedium>     $media
     * @param list<ParsedAttachment> $attachments
     */
    public static function assemble(?DeclaredImage $lead, array $media, array $attachments): AssembledMedia
    {
        return new AssembledMedia(
            self::visualMedia($lead, $media),
            self::attachments($attachments),
        );
    }

    /**
     * @param list<ParsedMedium> $media
     *
     * @return list<EntryMedium>
     */
    private static function visualMedia(?DeclaredImage $lead, array $media): array
    {
        $kept = [];
        $seen = [];

        $leadMedium = self::leadMedium($lead);
        if ($leadMedium !== null) {
            $kept[] = $leadMedium;
            $seen[$leadMedium->url] = true;
        }

        foreach ($media as $medium) {
            $url = HttpsImageUrl::orNull($medium->url);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $kept[] = new EntryMedium(
                $url,
                $medium->kind->value,
                $medium->width,
                $medium->height,
                HttpsImageUrl::orNull($medium->previewImageUrl),
            );
        }

        return $kept;
    }

    /** The lead is not yet verified, so it is upgraded rather than merely accepted — the same gate storeImage uses. */
    private static function leadMedium(?DeclaredImage $lead): ?EntryMedium
    {
        if ($lead === null) {
            return null;
        }
        $url = HttpsImageUrl::orNullUpgrading($lead->url);
        if ($url === null) {
            return null;
        }

        return new EntryMedium($url, VisualMediaKind::Image->value, $lead->width, $lead->height);
    }

    /**
     * @param list<ParsedAttachment> $attachments
     *
     * @return list<EntryAttachment>
     */
    private static function attachments(array $attachments): array
    {
        $kept = [];
        foreach ($attachments as $attachment) {
            $url = HttpsImageUrl::orNull($attachment->url);
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
}
