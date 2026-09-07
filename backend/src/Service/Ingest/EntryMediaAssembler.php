<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Service\Image\DeclaredImage;
use App\Service\Parser\ParsedAttachment;
use App\Service\Parser\ParsedMedium;
use App\Service\Url\HttpsImageUrl;

/**
 * Turns the feed's parsed media into the entity's two stored lists. The lead
 * image leads the visual list, so `media[0]` stays the persisted lead and equals
 * getImageUrl(); every URL passes the same https/length gate the lead image
 * uses, and a visual whose URL matches one already kept is dropped rather than
 * repeated.
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
            $kept[] = $medium->withUrl($url);
        }

        return $kept;
    }

    /**
     * @param list<ParsedMedium> $media
     *
     * @return list<EntryMedium>
     */
    private static function withLeadFirst(?DeclaredImage $lead, array $media): array
    {
        $mapped = array_map(
            static fn (ParsedMedium $medium): EntryMedium => new EntryMedium(
                $medium->url,
                $medium->kind->value,
                $medium->width,
                $medium->height,
                self::gatedOrNull($medium->previewImageUrl),
            ),
            $media,
        );

        if ($lead === null) {
            return $mapped;
        }

        return [new EntryMedium($lead->url, 'image', $lead->width, $lead->height), ...$mapped];
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

    private static function gatedOrNull(?string $url): ?string
    {
        return $url === null ? null : HttpsImageUrl::orNull($url);
    }
}
