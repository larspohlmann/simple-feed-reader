<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

final readonly class StoryMatchModel
{
    /**
     * @param list<StoryAssignmentModel> $assignments
     * @param list<StoryMergeModel>      $merges
     */
    public function __construct(
        public array $assignments,
        public array $merges,
        public int $split,
    ) {
    }

    /** @param list<StoryAssignmentModel> $assignments */
    #[\NoDiscard]
    public function withAssignments(array $assignments): self
    {
        return clone($this, ['assignments' => $assignments]);
    }

    public function kept(): int
    {
        return \count(array_filter(
            $this->assignments,
            static fn (StoryAssignmentModel $assignment): bool => $assignment->storyId !== null,
        ));
    }

    public function created(): int
    {
        return \count($this->assignments) - $this->kept();
    }

    public function merged(): int
    {
        return \count($this->merges);
    }

    /** @return array{kept: int, created: int, merged: int, split: int} */
    public function toLogContext(): array
    {
        return [
            'kept' => $this->kept(),
            'created' => $this->created(),
            'merged' => $this->merged(),
            'split' => $this->split,
        ];
    }
}
