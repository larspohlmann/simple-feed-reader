<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\StoryAi;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\StoryAiTask;
use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\StoryAi\Model\StoryAiOutcomeModel;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use App\Service\Curation\StoryAi\StoryAiTask\StoryAiTaskInterface;

/** A story AI task that records which stories the sweep ran, and is rate limited on the stories it is told. */
final class RecordingStoryAiTask implements StoryAiTaskInterface
{
    /** @var list<int> */
    public array $dueAskedFor = [];

    /** @var list<int> */
    public array $connectionAskedFor = [];

    /** @var list<array{int, int}> */
    public array $runs = [];

    /**
     * @param array<int, list<int>> $dueStoryIdsByUser
     * @param array<int, int> $rateLimitedStoryByUser
     */
    public function __construct(
        private readonly ?AiProviderSettings $connection,
        private readonly array $dueStoryIdsByUser,
        private readonly array $rateLimitedStoryByUser = [],
    ) {
    }

    public function task(): StoryAiTask
    {
        return StoryAiTask::Title;
    }

    public function findConnection(User $user): ?AiProviderSettings
    {
        $this->connectionAskedFor[] = $user->requireId();

        return $this->connection;
    }

    public function dueStoryIds(User $user, \DateTimeImmutable $now): array
    {
        $this->dueAskedFor[] = $user->requireId();

        return $this->dueStoryIdsByUser[$user->requireId()] ?? [];
    }

    /** @return StoryAiOutcomeModel<mixed> */
    public function run(StoryAiPass $pass, int $storyId): StoryAiOutcomeModel
    {
        $userId = $pass->user->requireId();
        $this->runs[] = [$userId, $storyId];
        if (($this->rateLimitedStoryByUser[$userId] ?? null) === $storyId) {
            throw new ProviderRateLimitedException(20);
        }

        return StoryAiOutcomeModel::usable('written');
    }
}
