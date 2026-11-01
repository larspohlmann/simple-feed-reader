<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** The link card an external embed's view shows; none without a web page's URL and a title. */
final class BlueskyExternalCard
{
    public static function of(JsonNodeModel $external): ?LinkCardContent
    {
        $url = AbsoluteHttpUrl::orNull($external->string('uri'));
        $title = $external->string('title');
        if ($url === null || $title === null) {
            return null;
        }

        return StorableLinkCard::of(new LinkCardContent(
            $url,
            $title,
            $external->string('description'),
            $external->string('thumb'),
            HostKey::forUrl($url),
        ));
    }

    private function __construct()
    {
    }
}
