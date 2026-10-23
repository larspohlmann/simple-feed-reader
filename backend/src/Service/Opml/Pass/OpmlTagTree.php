<?php

declare(strict_types=1);

namespace App\Service\Opml\Pass;

use App\Entity\Tag;
use App\Service\Social\Model\ExportedSubscriptionModel;

/** One export's tags and their feeds, arranged for nesting: a parent appears even when only its child has feeds. */
final class OpmlTagTree
{
    /** @var array<int, Tag> by tag id */
    private array $tags = [];

    /** @var array<int, list<ExportedSubscriptionModel>> by tag id */
    private array $feedsByTag = [];

    /** @var list<ExportedSubscriptionModel> */
    private array $untagged = [];

    /** @param list<ExportedSubscriptionModel> $subscriptions */
    public function __construct(array $subscriptions)
    {
        foreach ($subscriptions as $exported) {
            $tags = $exported->subscription->getTags();
            if ($tags->isEmpty()) {
                $this->untagged[] = $exported;
                continue;
            }
            foreach ($tags as $tag) {
                $this->remember($tag);
                $this->feedsByTag[$tag->requireId()][] = $exported;
            }
        }
    }

    /** @return list<Tag> */
    public function roots(): array
    {
        return $this->ordered(static fn (Tag $tag): bool => $tag->getParent() === null);
    }

    /** @return list<Tag> */
    public function childrenOf(Tag $parent): array
    {
        return $this->ordered(static fn (Tag $tag): bool => $tag->getParent()?->getId() === $parent->getId());
    }

    /** @return list<ExportedSubscriptionModel> */
    public function feedsOf(Tag $tag): array
    {
        return $this->feedsByTag[$tag->requireId()] ?? [];
    }

    /** @return list<ExportedSubscriptionModel> */
    public function untagged(): array
    {
        return $this->untagged;
    }

    private function remember(Tag $tag): void
    {
        $this->tags[$tag->requireId()] = $tag;
        $parent = $tag->getParent();
        if ($parent !== null) {
            $this->tags[$parent->requireId()] = $parent;
        }
    }

    /**
     * @param \Closure(Tag): bool $keep
     *
     * @return list<Tag>
     */
    private function ordered(\Closure $keep): array
    {
        $tags = array_values(array_filter($this->tags, $keep));
        usort($tags, static fn (Tag $left, Tag $right): int => [$left->getPosition(), $left->getName()]
            <=> [$right->getPosition(), $right->getName()]);

        return $tags;
    }
}
