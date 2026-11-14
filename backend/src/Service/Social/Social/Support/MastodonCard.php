<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Entity\LinkCardContent;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Support\HostKey;
use App\Service\Content\LinkCard\Support\StorableLinkCard;

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
