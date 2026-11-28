<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\StoryAi;

use App\Entity\User;
use App\Enum\StoryAiTask;
use App\Service\Curation\Ai\Exception\ProviderRateLimitedException;
use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Ai\Exception\RetryableProviderException;
use App\Service\Curation\Recommendation\Llm\Prompt\Model\CallPromptModel;
use App\Service\Curation\StoryAi\Model\StoryAiOutcomeModel;
use App\Service\Curation\StoryAi\Pass\StoryAiPass;
use App\Service\Curation\StoryAi\StoryAiCalls;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;

final class StoryAiCallsTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private StoryAiPass $pass;

    protected function setUp(): void
    {
        parent::setUp();
        $fixtures = RecommendationRunFixtures::fromContainer(self::getContainer());
        $this->owner = $this->user('story-ai-calls@example.test');
        $this->pass = new StoryAiPass(
            $this->owner,
            $fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b'),
            StoryAiTask::Title,
            new \DateTimeImmutable('2031-05-14 10:00:00'),
        );
    }

    public function testAParsedReplyIsUsableAndRecorded(): void
    {
        $this->chat()->queueContent('{"title":"x"}');

        $outcome = $this->complete();

        self::assertTrue($outcome->isUsable());
        self::assertSame('x', $outcome->value());
        self::assertSame(['title'], $this->runTasks());
        self::assertSame(['usable'], $this->logVerdicts());
    }

    public function testAReplyTheParserRejectsIsUnusable(): void
    {
        $this->chat()->queueContent('{}');

        $outcome = $this->complete();

        self::assertFalse($outcome->isUsable());
        self::assertFalse($outcome->isFailed());
        self::assertSame(['unusable'], $this->logVerdicts());
    }

    public function testATransportFailureIsAFailedOutcome(): void
    {
        $failure = new ProviderUnreachableException('gone');
        $this->chat()->queueFailure($failure);

        $outcome = $this->complete();

        self::assertFalse($outcome->isUsable());
        self::assertTrue($outcome->isFailed());
        self::assertSame($failure, $outcome->failure());
        self::assertSame(['transport-failed'], $this->logVerdicts());
    }

    public function testARateLimitIsRethrown(): void
    {
        $this->chat()->queueFailure(new RetryableProviderException(429, 20));

        try {
            $this->complete();
            self::fail('A rate limit must propagate.');
        } catch (ProviderRateLimitedException) {
            self::assertSame(['transport-failed'], $this->logVerdicts());
        }
    }

    public function testTwoCallsOfOnePassShareOneRun(): void
    {
        $this->chat()->queueContent('{"title":"x"}');
        $this->chat()->queueContent('{"title":"y"}');

        $this->complete();
        $this->complete();

        self::assertSame(['title'], $this->runTasks());
        self::assertSame(['usable', 'usable'], $this->logVerdicts());
    }

    public function testAPassWithoutCallsLeavesNoRun(): void
    {
        self::assertSame([], $this->runTasks());
    }

    public function testThePassIsPastItsDeadlineFromTheDeadlineOn(): void
    {
        self::assertFalse($this->pass->isPastDeadline(new \DateTimeImmutable('2031-05-14 09:59:59')));
        self::assertTrue($this->pass->isPastDeadline(new \DateTimeImmutable('2031-05-14 10:00:00')));
    }

    /** @return StoryAiOutcomeModel<string> */
    private function complete(): StoryAiOutcomeModel
    {
        return $this->calls()->complete(
            $this->pass,
            CallPromptModel::storyTitle([['role' => 'user', 'content' => 'Headlines']]),
            static function (string $content): ?string {
                $decoded = json_decode($content, true);

                return \is_array($decoded) && \is_string($decoded['title'] ?? null) ? $decoded['title'] : null;
            },
        );
    }

    /** @return list<string> */
    private function runTasks(): array
    {
        /** @var list<string> $tasks */
        $tasks = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT task FROM story_ai_run WHERE user_id = ?',
            [$this->owner->requireId()],
        );

        return $tasks;
    }

    /** @return list<string> */
    private function logVerdicts(): array
    {
        /** @var list<string> $verdicts */
        $verdicts = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT log.verdict FROM recommendation_run_log log'
            . ' JOIN story_ai_run run ON run.id = log.story_ai_run_id WHERE run.user_id = ? ORDER BY log.id',
            [$this->owner->requireId()],
        );

        return $verdicts;
    }

    private function calls(): StoryAiCalls
    {
        /** @var StoryAiCalls $calls */
        $calls = self::getContainer()->get(StoryAiCalls::class);

        return $calls;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
