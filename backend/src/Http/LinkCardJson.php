<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;

/**
 * @phpstan-type LinkCardRow array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
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

    /** @return LinkCardRow|null */
    public static function ofOrNull(?LinkCardContent $card): ?array
    {
        return $card === null ? null : self::of($card);
    }
}
