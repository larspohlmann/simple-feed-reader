<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\Model\AssembledMediaModel;
use App\Service\Parser\Model\ParsedAttachmentModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Url\HttpsImageUrl;

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

    private static function leadMedium(?DeclaredImageModel $lead): ?EntryMedium
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
     * @param list<ParsedAttachmentModel> $attachments
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
