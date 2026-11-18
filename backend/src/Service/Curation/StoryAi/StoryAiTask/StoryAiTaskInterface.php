<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi\StoryAiTask;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Enum\StoryAiTask;
use App\Service\Curation\StoryAi\Model\StoryAiOutcomeModel;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** A new task is one class plus a StoryAiTask case, whose phase() names the CallPhase its calls log under. */
#[AutoconfigureTag('app.story_ai_task')]
interface StoryAiTaskInterface
{
    public function task(): StoryAiTask;

    /** The connection this task calls for the user; null skips the user for this task. */
    public function connectionFor(User $user): ?AiProviderSettings;

    /** @return list<int> */
    public function dueStoryIds(User $user, \DateTimeImmutable $now): array;

    /**
     * Applies the result, or stamps the attempt, itself; a ProviderRateLimitedException stops the user's sweep.
     *
     * @return StoryAiOutcomeModel<mixed>
     */
    public function run(StoryAiPass $pass, int $storyId): StoryAiOutcomeModel;
}
