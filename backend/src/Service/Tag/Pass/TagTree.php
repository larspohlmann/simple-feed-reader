<?php

declare(strict_types=1);

namespace App\Service\Tag\Pass;

use App\Entity\Tag;

final readonly class TagTree
{
    /** @var array<int, int|null> parent id by tag id */
    private array $parentIds;

    /** @var array<int, list<int>> child ids by parent id */
    private array $childIds;

    /** @param list<Tag> $tags */
    public function __construct(array $tags)
    {
        $parentIds = [];
        $childIds = [];
        foreach ($tags as $tag) {
            $parentId = $tag->getParent()?->requireId();
            $parentIds[$tag->requireId()] = $parentId;
            if ($parentId !== null) {
                $childIds[$parentId][] = $tag->requireId();
            }
        }
        $this->parentIds = $parentIds;
        $this->childIds = $childIds;
    }

    public function depthOf(int $tagId): int
    {
        $parentId = $this->parentIds[$tagId] ?? null;

        return $parentId === null ? 0 : $this->depthOf($parentId) + 1;
    }

    public function heightOf(int $tagId): int
    {
        $height = 0;
        foreach ($this->childIds[$tagId] ?? [] as $childId) {
            $height = max($height, $this->heightOf($childId) + 1);
        }

        return $height;
    }

    /** Whether $tagId is $ancestorId itself or sits anywhere below it. */
    public function isWithin(int $tagId, int $ancestorId): bool
    {
        if ($tagId === $ancestorId) {
            return true;
        }
        $parentId = $this->parentIds[$tagId] ?? null;

        return $parentId !== null && $this->isWithin($parentId, $ancestorId);
    }
}
