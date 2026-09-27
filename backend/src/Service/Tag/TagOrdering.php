<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Ordering\PositionReorderer;
use App\Service\Reader\ExactSetGuard;

final readonly class TagOrdering
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private ExactSetGuard $exactSet,
        private PositionReorderer $reorderer,
    ) {
    }

    /**
     * @param list<int> $tagIds the user's tags in their new order
     *
     * @return list<Tag>
     */
    public function reorder(User $user, array $tagIds): array
    {
        $byId = $this->ownedTagsById($user);
        $this->exactSet->assertPermutation($tagIds, array_keys($byId), 'tagIds must list exactly your tags.');
        $this->reorderer->reorder($tagIds, $byId);

        return array_map(static fn (int $id): Tag => $byId[$id], $tagIds);
    }

    /** @param list<int> $subscriptionIds the tag's feeds in their new order */
    public function orderFeeds(Tag $tag, array $subscriptionIds): void
    {
        $joinsBySubscriptionId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->exactSet->assertPermutation(
            $subscriptionIds,
            array_keys($joinsBySubscriptionId),
            "subscriptionIds must list exactly this tag's feeds.",
        );
        $this->reorderer->reorder($subscriptionIds, $joinsBySubscriptionId);
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
