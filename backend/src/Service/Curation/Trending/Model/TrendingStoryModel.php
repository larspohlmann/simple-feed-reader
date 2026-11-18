<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class TrendingStoryModel
{
    /** @param non-empty-list<StoryMemberModel> $members */
    public function __construct(
        public array $members,
        public \DateTimeImmutable $firstSeen,
        public \DateTimeImmutable $lastSeen,
    ) {
    }

    /** @return non-empty-list<int> */
    public function entryIds(): array
    {
        return array_map(static fn (StoryMemberModel $member): int => $member->entryId, $this->members);
    }

    /** @return list<StoryMemberModel> */
    public function outletMembers(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (StoryMemberModel $member): bool => $member->fromAnOutlet,
        ));
    }

    public function outletCount(): int
    {
        return \count(array_unique(array_map(
            static fn (StoryMemberModel $member): int => $member->feedId,
            $this->members,
        )));
    }
}
