<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\ProviderCredentials;
use App\Service\Recommendation\ChatCompletionClient;
use App\Service\Recommendation\CompletionRequest;
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

    private ?\Closure $duringNextCall = null;

    /**
     * @var list<array{
     *     model: string,
     *     messages: list<array{role: string, content: string}>,
     *     maxAnswerTokens: int,
     *     responseSchemaName: string,
     * }>
     */
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
     * Runs inside the next complete(), before it answers.
     *
     * A provider call is the one window where the world can change underneath
     * a tick — it is the only part that takes minutes — so a test that needs
     * to model "something happened while the model was thinking" has nowhere
     * else to stand. Cancellation is exactly that test.
     */
    public function duringNextCall(\Closure $hook): void
    {
        $this->duringNextCall = $hook;
    }

    /**
     * @return list<array{
     *     model: string,
     *     messages: list<array{role: string, content: string}>,
     *     maxAnswerTokens: int,
     *     responseSchemaName: string,
     * }>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function complete(
        ProviderCredentials $credentials,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
    ): string {
        // maxAnswerTokens is recorded alongside the prompt so a test can prove
        // the answer bound was derived from the batch it belongs to, rather
        // than from a constant that happens to be large enough today.
        $this->calls[] = [
            'model' => $request->model,
            'messages' => $request->messages,
            'maxAnswerTokens' => $request->maxAnswerTokens,
            // The schema name proves each phase asked for its own structured
            // shape -- a batch call for the ranking, a dedup call for the
            // duplicate list -- rather than sharing one (#329).
            'responseSchemaName' => $request->responseSchema->name,
        ];

        if (null !== $this->duringNextCall) {
            $hook = $this->duringNextCall;
            $this->duringNextCall = null;
            $hook();
        }

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
