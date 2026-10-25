<?php

declare(strict_types=1);

namespace App\Service\Subscription\Pass;

use App\Entity\Tag;
use App\Service\Tag\Pass\TagTree;

/** The user's tags as this batch leaves them, keyed by spl_object_id: a tag created here has no id until the flush. */
final class BulkSubscribeTagTree
{
    /** @var array<int, int|null> parent key by tag key */
    private array $parentKeys = [];

    /** @param list<Tag> $storedTags */
    public function __construct(array $storedTags)
    {
        foreach ($storedTags as $tag) {
            $this->parentKeys[spl_object_id($tag)] = self::keyOf($tag->getParent());
        }
    }

    public function add(Tag $tag): void
    {
        $this->parentKeys[spl_object_id($tag)] = null;
    }

    public function nest(Tag $tag, Tag $parent): void
    {
        $this->parentKeys[spl_object_id($tag)] = spl_object_id($parent);
    }

    public function fitsUnder(Tag $tag, Tag $parent): bool
    {
        return $this->tree()->canPlace(spl_object_id($tag), spl_object_id($parent));
    }

    public function fitsUnderANewTag(Tag $tag): bool
    {
        return TagTree::fits(0, $this->tree()->heightOf(spl_object_id($tag)));
    }

    private function tree(): TagTree
    {
        return new TagTree($this->parentKeys);
    }

    private static function keyOf(?Tag $tag): ?int
    {
        return $tag === null ? null : spl_object_id($tag);
    }
}
