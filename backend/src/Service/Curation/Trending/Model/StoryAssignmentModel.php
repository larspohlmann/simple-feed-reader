<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class StoryAssignmentModel
{
    /** @param list<int> $storedEntryIds empty when the group is a new story */
    public function __construct(
        public TrendingStoryModel $group,
        public ?int $storyId,
        public array $storedEntryIds,
    ) {
    }

    /** @return list<StoryMemberModel> */
    public function joining(): array
    {
        return array_values(array_filter(
            $this->group->members,
            fn (StoryMemberModel $member): bool => !\in_array($member->entryId, $this->storedEntryIds, true),
        ));
    }

    /** @return list<int> */
    public function staying(): array
    {
        return array_values(array_filter(
            $this->group->entryIds(),
            fn (int $entryId): bool => \in_array($entryId, $this->storedEntryIds, true),
        ));
    }
}
