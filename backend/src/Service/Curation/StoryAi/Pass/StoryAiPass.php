<?php

declare(strict_types=1);

namespace App\Service\Curation\StoryAi\Pass;

use App\Entity\AiProviderSettings;
use App\Entity\StoryAiRun;
use App\Entity\User;
use App\Enum\StoryAiTask;

/** One user's turn at one story AI task: the connection it resolved, its deadline, and the run its calls bill. */
final class StoryAiPass
{
    private ?StoryAiRun $run = null;

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public readonly User $user,
        public readonly AiProviderSettings $connection,
        public readonly StoryAiTask $task,
        public readonly ?\DateTimeImmutable $deadline,
    ) {
    }

    public function isPastDeadline(\DateTimeImmutable $now): bool
    {
        return $this->deadline !== null && $now >= $this->deadline;
    }

    /** Null until the first call opens it, so a pass that calls nothing leaves no row. */
    public function run(): ?StoryAiRun
    {
        return $this->run;
    }

    public function billTo(StoryAiRun $run): void
    {
        $this->run = $run;
    }
}
