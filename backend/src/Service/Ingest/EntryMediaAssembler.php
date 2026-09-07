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
 * image leads the visual list, so `media[0]` stays the persisted lead and equals
 * getImageUrl(); every URL passes the same https/length gate the lead image
 * uses, and a visual whose URL matches one already kept is dropped rather than
 * repeated.
 *
 * Storing the lead both here (as media[0]) and in the EntryImage columns is a
 * deliberate denormalization: media[] is a self-contained visual list a native
 * client renders alone. The `media[0].url === getImageUrl()` invariant it relies
 * on is held by running the one gate over the same lead, and is guarded by
 * EntryIngestorTest.
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
        foreach (self::withLeadFirst($lead, $media) as $medium) {
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

    /**
     * @param list<ParsedMedium> $media
     *
     * @return list<ParsedMedium>
     */
    private static function withLeadFirst(?DeclaredImage $lead, array $media): array
    {
        if ($lead === null) {
            return $media;
        }

        $leadMedium = new ParsedMedium($lead->url, VisualMediaKind::Image, $lead->width, $lead->height);

        return [$leadMedium, ...$media];
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
