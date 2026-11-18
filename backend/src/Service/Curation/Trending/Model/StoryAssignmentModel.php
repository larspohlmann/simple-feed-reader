<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class StoryAssignmentModel
{
    /**
     * @param list<int> $storedEntryIds  empty when the group is a new story
     * @param list<int> $claimedEntryIds the group's entries another stored story holds after this build's merges
     */
    public function __construct(
        public TrendingStoryModel $group,
        public ?int $storyId,
        public array $storedEntryIds,
        public array $claimedEntryIds = [],
        public ?FallbackTitleModel $fallbackTitle = null,
    ) {
    }

    #[\NoDiscard]
    public function titled(FallbackTitleModel $title): self
    {
        return clone($this, ['fallbackTitle' => $title]);
    }

    /** @param list<int> $claimedEntryIds */
    #[\NoDiscard]
    public function claiming(array $claimedEntryIds): self
    {
        return clone($this, ['claimedEntryIds' => $claimedEntryIds]);
    }

    /** @return list<StoryMemberModel> */
    public function joining(): array
    {
        $stored = array_flip($this->storedEntryIds);

        return array_values(array_filter(
            $this->group->members,
            static fn (StoryMemberModel $member): bool => !isset($stored[$member->entryId]),
        ));
    }
}
