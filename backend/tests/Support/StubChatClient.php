<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\ProviderCredentials;
use App\Service\Recommendation\ChatCompletionClient;
use App\Service\Recommendation\CompletionStreamObserver;

/**
 * Records every complete() call and answers with a queued response, so
 * recommendation tests can assert exactly which prompts reached the model
 * without a live provider call. Registered as the container's
 * ChatCompletionClient in the test environment (services_test.yaml), so it
 * stands in wherever the production alias would resolve to
 * OpenAiCompatibleChatClient.
 *
 * Content and failures share one FIFO queue rather than two, so a test that
 * queues "fail, then succeed" (to prove a corrective retry) gets that exact
 * order regardless of which queue* method it called first.
 */
final class StubChatClient implements ChatCompletionClient
{
    /** @var list<string|\RuntimeException> */
    private array $queue = [];

    /** @var list<array{model: string, messages: list<array{role: string, content: string}>}> */
    private array $calls = [];

    public function queueContent(string $content): void
    {
        $this->queue[] = $content;
    }

    public function queueFailure(\RuntimeException $e): void
    {
        $this->queue[] = $e;
    }

    /**
     * @return list<array{model: string, messages: list<array{role: string, content: string}>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function complete(
        ProviderCredentials $credentials,
        string $model,
        array $messages,
        CompletionStreamObserver $observer,
    ): string {
        $this->calls[] = ['model' => $model, 'messages' => $messages];

        if ([] === $this->queue) {
            throw new \LogicException('StubChatClient has no queued response left.');
        }

        $next = array_shift($this->queue);
        if ($next instanceof \RuntimeException) {
            throw $next;
        }

        return $next;
    }
}
