<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\LinkCardContent;
use App\Service\Text\Support\WordBoundary;

/** A card cut to what link_card holds: none for a blank title or a URL too long to store, no over-long image URL. */
final class StorableLinkCard
{
    private const int DESCRIPTION_MAX = 300;

    public static function of(LinkCardContent $card): ?LinkCardContent
    {
        if (trim($card->title) === '' || mb_strlen($card->url) > LinkCardContent::URL_MAX) {
            return null;
        }

        return new LinkCardContent(
            $card->url,
            mb_substr($card->title, 0, LinkCardContent::TITLE_MAX),
            $card->description === null ? null : WordBoundary::clipped($card->description, self::DESCRIPTION_MAX),
            mb_strlen((string) $card->imageUrl) > LinkCardContent::URL_MAX ? null : $card->imageUrl,
            mb_substr($card->siteName, 0, LinkCardContent::SITE_NAME_MAX),
        );
    }

    private function __construct()
    {
    }
}
