<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Tag;

final class TagJson
{
    /** @return array{id: int|null, name: string, color: string|null, icon: string|null} */
    public static function one(Tag $tag): array
    {
        return [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'color' => $tag->getColor(),
            'icon' => $tag->getIcon(),
        ];
    }
}
