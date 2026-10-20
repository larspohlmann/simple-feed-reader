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
final readonly class LinkCardJson
{
    /** @return LinkCardRow */
    public function of(LinkCardContent $card): array
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
    public function placed(PlacedLinkCardModel $card): array
    {
        return $this->of($card->content) + ['position' => $card->position];
    }

    /** @return LinkCardRow|null */
    public function ofOrNull(?LinkCardContent $card): ?array
    {
        return $card === null ? null : $this->of($card);
    }
}
