<?php

declare(strict_types=1);

namespace App\Service\Subscription\Pass;

use App\Entity\Tag;
use App\Service\Tag\Pass\TagTree;

/** The user's tags as this batch leaves them, keyed by spl_object_id: a tag created here has no id until the flush. */
final class BulkSubscribeTagTree
{
    /** @var array<int, Tag> by spl_object_id */
    private array $tags = [];

    /** @param list<Tag> $storedTags */
    public function __construct(array $storedTags)
    {
        foreach ($storedTags as $tag) {
            $this->add($tag);
        }
    }

    public function add(Tag $tag): void
    {
        $this->tags[spl_object_id($tag)] = $tag;
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
        return new TagTree(array_map(
            static fn (Tag $tag): ?int => $tag->getParent() === null ? null : spl_object_id($tag->getParent()),
            $this->tags,
        ));
    }
}
