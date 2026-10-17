<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Service\LinkCard\Model\PlacedLinkCardModel;

/**
 * @phpstan-type LinkCardRow array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
 * }
 * @phpstan-type PlacedLinkCardRow array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string, position: int
 * }
 */
final class LinkCardJson
{
    /** @return LinkCardRow */
    public static function of(LinkCardContent $card): array
    {
        return [
            'url' => $card->url,
            'title' => $card->title,
            'description' => $card->description,
            'imageUrl' => $card->imageUrl,
            'siteName' => $card->siteName,
        ];
    }

    /** @return PlacedLinkCardRow */
    public static function placed(PlacedLinkCardModel $card): array
    {
        return self::of($card->content) + ['position' => $card->position];
    }

    /** @return LinkCardRow|null */
    public static function ofOrNull(?LinkCardContent $card): ?array
    {
        return $card === null ? null : self::of($card);
    }
}
