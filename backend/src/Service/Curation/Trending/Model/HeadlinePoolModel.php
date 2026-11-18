<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** The members a story may take its title from, scored against all of the story's members. */
final readonly class HeadlinePoolModel
{
    /**
     * @param list<StoryMemberModel> $members
     * @param list<int>              $storyEntryIds
     */
    public function __construct(
        public array $members,
        public array $storyEntryIds,
        public ?int $currentEntryId,
    ) {
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_map(static fn (StoryMemberModel $member): int => $member->entryId, $this->members);
    }
}
