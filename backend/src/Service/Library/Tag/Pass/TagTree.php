<?php

declare(strict_types=1);

namespace App\Service\Library\Tag\Pass;

use App\Entity\Tag;

final readonly class TagTree
{
    /** @var array<int, list<int>> child ids by parent id */
    private array $childIds;

    /** @param array<int, int|null> $parentIds parent id by tag id */
    public function __construct(private array $parentIds)
    {
        $childIds = [];
        foreach ($parentIds as $tagId => $parentId) {
            if ($parentId !== null) {
                $childIds[$parentId][] = $tagId;
            }
        }
        $this->childIds = $childIds;
    }

    /** @param list<Tag> $tags */
    public static function fromTags(array $tags): self
    {
        $parentIds = [];
        foreach ($tags as $tag) {
            $parentIds[$tag->requireId()] = $tag->getParent()?->requireId();
        }

        return new self($parentIds);
    }

    public function computeDepth(int $tagId): int
    {
        $parentId = $this->parentIds[$tagId] ?? null;

        return $parentId === null ? 0 : $this->computeDepth($parentId) + 1;
    }

    public function computeHeight(int $tagId): int
    {
        $height = 0;
        foreach ($this->childIds[$tagId] ?? [] as $childId) {
            $height = max($height, $this->computeHeight($childId) + 1);
        }

        return $height;
    }

    public static function fits(int $parentDepth, int $height): bool
    {
        return $parentDepth + 1 + $height <= Tag::MAX_DEPTH;
    }

    public function canPlace(int $tagId, int $parentId): bool
    {
        return !$this->isWithin($parentId, $tagId)
            && self::fits($this->computeDepth($parentId), $this->computeHeight($tagId));
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
