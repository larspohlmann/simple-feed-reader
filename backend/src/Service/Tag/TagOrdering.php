<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidSelectionException;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Ordering\PositionReorderer;

final readonly class TagOrdering
{
    private const string SIBLING_SET_MESSAGE = 'tagIds must list exactly one tag and all of its siblings.';

    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private ExactSetGuard $exactSet,
        private PositionReorderer $reorderer,
    ) {
    }

    /**
     * @param list<int> $orderedTagIds
     *
     * @return list<Tag>
     */
    public function reorder(User $user, array $orderedTagIds): array
    {
        $siblings = $this->siblingsOf($this->ownedTagsById($user), $orderedTagIds);
        $this->exactSet->assertPermutation($orderedTagIds, array_keys($siblings), self::SIBLING_SET_MESSAGE);
        $this->reorderer->reorder($orderedTagIds, $siblings);

        return array_map(static fn (int $id): Tag => $siblings[$id], $orderedTagIds);
    }

    /** @param list<int> $orderedSubscriptionIds */
    public function orderFeeds(Tag $tag, array $orderedSubscriptionIds): void
    {
        $joinsBySubscriptionId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->exactSet->assertPermutation(
            $orderedSubscriptionIds,
            array_keys($joinsBySubscriptionId),
            "subscriptionIds must list exactly this tag's feeds.",
        );
        $this->reorderer->reorder($orderedSubscriptionIds, $joinsBySubscriptionId);
    }

    /**
     * The owned tags sharing the first listed tag's parent: all roots, or all children of one parent.
     *
     * @param array<int, Tag> $byId
     * @param list<int>       $orderedTagIds
     *
     * @return array<int, Tag>
     */
    private function siblingsOf(array $byId, array $orderedTagIds): array
    {
        $first = $byId[$orderedTagIds[0] ?? 0] ?? throw new InvalidSelectionException(self::SIBLING_SET_MESSAGE);
        $parentId = $first->getParent()?->getId();

        return array_filter($byId, static fn (Tag $tag): bool => $tag->getParent()?->getId() === $parentId);
    }

    /** @return array<int, Tag> */
    private function ownedTagsById(User $user): array
    {
        $byId = [];
        foreach ($this->tags->findForUser($user->requireId()) as $tag) {
            $byId[$tag->requireId()] = $tag;
        }

        return $byId;
    }
}
