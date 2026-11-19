<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Support;

use App\Entity\LinkCardContent;
use App\Service\Foundation\Text\Support\WordBoundary;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Foundation\Url\Support\HttpsImageUrl;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;

/**
 * A card as shared_link may hold it, whoever made it: none for a blank title or a URL that is no web page or too long
 * to store, its URL without tracking parameters, no description that is a bare URL, and no image unless its URL is
 * https and fits.
 */
final class StorableLinkCard
{
    private const string BARE_URL = '~^https?://\S+$~';

    public static function of(LinkCardContent $card): ?LinkCardContent
    {
        if (trim($card->title) === '' || !self::fits($card->url) || !AbsoluteHttpUrl::matches($card->url)) {
            return null;
        }

        return new LinkCardContent(
            TrackingFreeUrl::stripTracking($card->url),
            mb_substr($card->title, 0, LinkCardContent::TITLE_MAX),
            self::description($card->description),
            self::fits((string) $card->imageUrl) ? HttpsImageUrl::orNull($card->imageUrl) : null,
            mb_substr($card->siteName, 0, LinkCardContent::SITE_NAME_MAX),
        );
    }

    private static function description(?string $description): ?string
    {
        if ($description === null || preg_match(self::BARE_URL, trim($description)) === 1) {
            return null;
        }

        return WordBoundary::clipped($description, LinkCardContent::DESCRIPTION_MAX);
    }

    private static function fits(string $url): bool
    {
        return mb_strlen($url) <= LinkCardContent::URL_MAX;
    }

    private function __construct()
    {
    }
}
