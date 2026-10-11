<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Support;

use App\Entity\LinkCardContent;
use App\Service\Text\Support\WordBoundary;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\HttpsImageUrl;

/**
 * A card as link_card may hold it, whoever made it: none for a blank title or a URL that is no web page or too long
 * to store, and no image unless its URL is https and fits.
 */
final class StorableLinkCard
{
    private const int DESCRIPTION_MAX = 300;

    public static function of(LinkCardContent $card): ?LinkCardContent
    {
        if (trim($card->title) === '' || !self::fits($card->url) || !AbsoluteHttpUrl::matches($card->url)) {
            return null;
        }

        return new LinkCardContent(
            $card->url,
            mb_substr($card->title, 0, LinkCardContent::TITLE_MAX),
            $card->description === null ? null : WordBoundary::clipped($card->description, self::DESCRIPTION_MAX),
            self::fits((string) $card->imageUrl) ? HttpsImageUrl::orNull($card->imageUrl) : null,
            mb_substr($card->siteName, 0, LinkCardContent::SITE_NAME_MAX),
        );
    }

    private static function fits(string $url): bool
    {
        return mb_strlen($url) <= LinkCardContent::URL_MAX;
    }

    private function __construct()
    {
    }
}
