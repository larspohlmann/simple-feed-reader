<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Dto\Tag\ReorderTagsRequest;
use App\Dto\Tag\TagFeedOrderRequest;
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

    /** @return list<Tag> */
    public function reorder(User $user, ReorderTagsRequest $request): array
    {
        $byId = $this->ownedTagsById($user);
        $this->exactSet->assertPermutation($request->tagIds, array_keys($byId), 'tagIds must list exactly your tags.');
        $this->reorderer->reorder($request->tagIds, $byId);

        return array_map(static fn (int $id): Tag => $byId[$id], $request->tagIds);
    }

    public function orderFeeds(Tag $tag, TagFeedOrderRequest $request): void
    {
        $joinsBySubscriptionId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->exactSet->assertPermutation(
            $request->subscriptionIds,
            array_keys($joinsBySubscriptionId),
            "subscriptionIds must list exactly this tag's feeds.",
        );
        $this->reorderer->reorder($request->subscriptionIds, $joinsBySubscriptionId);
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
