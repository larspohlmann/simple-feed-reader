<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Curation\Trending\Model\StoryFaceModel;

final readonly class StoryCardJson
{
    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     synopsis: string|null,
     *     outletCount: int,
     *     shareCount: int,
     *     followed: bool,
     *     createdAt: string,
     *     lastAddedAt: string,
     * }
     */
    public function from(StoryFaceModel $face): array
    {
        return [
            'id' => $face->storyId,
            'title' => $face->heading->title,
            'synopsis' => $face->heading->synopsis,
            'outletCount' => $face->sources->outletCount,
            'shareCount' => $face->sources->shareCount,
            'followed' => $face->followed,
            'createdAt' => $face->lifespan->createdAt->format(\DateTimeInterface::ATOM),
            'lastAddedAt' => $face->lifespan->lastAddedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
