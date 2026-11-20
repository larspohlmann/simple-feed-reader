<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Support\StorableLinkCard;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Support\HostKey;

final class MastodonCard
{
    public static function fromNode(JsonNodeModel $card): ?LinkCardContent
    {
        $url = $card->string('url');
        $title = $card->string('title');
        if (null === $url || null === $title) {
            return null;
        }

        return StorableLinkCard::fromCard(new LinkCardContent(
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
