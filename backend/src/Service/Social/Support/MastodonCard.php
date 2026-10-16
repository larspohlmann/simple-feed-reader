<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\LinkCard\Support\StorableLinkCard;

/** A Mastodon status's preview card as a link card. */
final class MastodonCard
{
    public static function of(JsonNodeModel $card): ?LinkCardContent
    {
        $url = $card->string('url');
        $title = $card->string('title');
        if (null === $url || null === $title) {
            return null;
        }

        return StorableLinkCard::of(new LinkCardContent(
            $url,
            $title,
            $card->string('description'),
            $card->string('image'),
            $card->string('provider_name') ?? HostKey::forUrl($url),
        ));
    }

    private function __construct()
    {
    }
}
