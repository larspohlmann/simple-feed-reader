<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\ProviderConnection;
use App\Service\Ai\ProviderCredentials;
use App\Service\Ai\ProviderTimeouts;
use App\Service\Recommendation\CompletionRequest;
use App\Service\Recommendation\ConcurrentCompletion;
use App\Service\Recommendation\Exception\RecommendationRunRateLimitedException;
use App\Service\Recommendation\JsonSchema;
use App\Service\Recommendation\NullCompletionStreamObserver;
use App\Service\Recommendation\RateLimitedCompletion;
use App\Service\Recommendation\RetryPlan;
use App\Service\Recommendation\TickDriver;
use App\Tests\Support\StubChatClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RateLimitedCompletionTest extends TestCase
{
    private const string START_AT = '2026-01-01T00:00:00Z';

    private function connection(): ProviderConnection
    {
        return new ProviderConnection(
            ProviderCredentials::fromStoredConfiguration('https://api.example.test/v1', 'sk-test'),
            ProviderTimeouts::standard(),
        );
    }

    private function request(): CompletionRequest
    {
        return new CompletionRequest(
            'm',
            [['role' => 'user', 'content' => 'x']],
            2048,
            new JsonSchema('s', ['type' => 'object']),
            false,
        );
    }

    /**
     * @param positive-int $count
     *
     * @return non-empty-list<ConcurrentCompletion>
     */
    private function calls(int $count): array
    {
        $calls = [new ConcurrentCompletion($this->request(), new NullCompletionStreamObserver())];
        for ($i = 1; $i < $count; $i++) {
            $calls[] = new ConcurrentCompletion($this->request(), new NullCompletionStreamObserver());
        }

        return $calls;
    }

    private function newClock(): MockClock
    {
        return new MockClock(new \DateTimeImmutable(self::START_AT));
    }

    private function elapsedSeconds(MockClock $clock): int
    {
        return $clock->now()->getTimestamp() - (new \DateTimeImmutable(self::START_AT))->getTimestamp();
    }

    public function testWorkerRetriesTheRateLimitedCallAndRecovers(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429));
        $chat->queueContent('{"ok":1}');
        $clock = $this->newClock();

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertFalse($result->isDeferred());
        self::assertTrue($result->rateLimitObserved);
        self::assertSame('{"ok":1}', $result->outcomes[0]->content());
        self::assertSame(1, $this->elapsedSeconds($clock));
    }

    public function testWorkerWaitsOneTwoFourAcrossThreeRetries(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429)); // initial send
        $chat->queueFailure(new RetryableProviderException(429)); // retry 1 (after 1 s)
        $chat->queueFailure(new RetryableProviderException(429)); // retry 2 (after 2 s)
        $chat->queueContent('{"ok":1}');                          // retry 3 (after 4 s)
        $clock = $this->newClock();

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertSame('{"ok":1}', $result->outcomes[0]->content());
        self::assertSame(7, $this->elapsedSeconds($clock));
    }

    public function testRetryAfterOverridesTheBackoffWait(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 5));
        $chat->queueContent('{"ok":1}');
        $clock = $this->newClock();

        (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertSame(5, $this->elapsedSeconds($clock));
    }

    public function testWorkerDefersWhenTheWaitWouldCrossTheBudget(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 200));
        $clock = $this->newClock();

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertTrue($result->isDeferred());
        self::assertSame(200.0, $result->deferSeconds);
        self::assertSame(0, $this->elapsedSeconds($clock));
    }

    public function testWorkerExhaustsRetriesAndKeepsTheRetryableFailure(): void
    {
        $chat = new StubChatClient();
        for ($i = 0; $i < 4; $i++) { // initial + 3 retries, all 429
            $chat->queueFailure(new RetryableProviderException(429));
        }
        $clock = $this->newClock();

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Worker));

        self::assertFalse($result->isDeferred());
        self::assertTrue($result->rateLimitObserved);
        self::assertTrue($result->outcomes[0]->isRetryable());
        self::assertSame(7, $this->elapsedSeconds($clock));
    }

    public function testPollDefersImmediatelyWithoutWaiting(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 15));
        $clock = $this->newClock();

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(1), RetryPlan::forDriver(TickDriver::Poll));

        self::assertTrue($result->isDeferred());
        self::assertSame(15.0, $result->deferSeconds);
        self::assertSame(0, $this->elapsedSeconds($clock));
    }

    public function testWorkerRefiresOnlyTheRateLimitedCallOfAWave(): void
    {
        $chat = new StubChatClient();
        $chat->queueContent('{"a":1}');                           // call 0 answers
        $chat->queueFailure(new RetryableProviderException(429));  // call 1 is limited
        $chat->queueContent('{"b":2}');                           // call 1 on retry
        $clock = $this->newClock();

        $result = (new RateLimitedCompletion($chat, $clock))
            ->completeMany($this->connection(), $this->calls(2), RetryPlan::forDriver(TickDriver::Worker));

        self::assertSame('{"a":1}', $result->outcomes[0]->content());
        self::assertSame('{"b":2}', $result->outcomes[1]->content());
        // 2 initial calls + 1 re-fired call proves only the limited call was re-sent.
        self::assertCount(3, $chat->calls());
    }

    public function testCompleteThrowsTheDeferralForAPollTick(): void
    {
        $chat = new StubChatClient();
        $chat->queueFailure(new RetryableProviderException(429, 8));
        $clock = $this->newClock();

        $this->expectException(RecommendationRunRateLimitedException::class);

        (new RateLimitedCompletion($chat, $clock))->complete(
            $this->connection(),
            $this->request(),
            new NullCompletionStreamObserver(),
            RetryPlan::forDriver(TickDriver::Poll),
        );
    }
}
