<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\ProviderConnection;
use App\Service\Recommendation\ChatCompletionClient;
use App\Service\Recommendation\CompletionOutcome;
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
     *     suppressReasoning: bool,
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
     *     suppressReasoning: bool,
     * }>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function complete(
        ProviderConnection $connection,
        CompletionRequest $request,
        CompletionStreamObserver $observer,
    ): string {
        $next = $this->answer($request);
        if ($next instanceof \RuntimeException) {
            throw $next;
        }

        return $next;
    }

    /**
     * Answers each call from the same FIFO queue as complete(), but folds a
     * queued failure into that call's outcome rather than throwing — the
     * concurrent contract, where one failed call never aborts its siblings
     * (#344). Outcomes stay aligned to $calls by index.
     */
    public function completeMany(ProviderConnection $connection, array $calls): array
    {
        $outcomes = [];

        foreach ($calls as $call) {
            $next = $this->answer($call->request);
            $outcomes[] = $next instanceof \RuntimeException
                ? CompletionOutcome::failure($next)
                : CompletionOutcome::answer($next);
        }

        return $outcomes;
    }

    /**
     * Records the prompt, runs the one-shot hook, and returns the next queued
     * response — a string answer or the failure to surface. Shared by both
     * read methods so they record and dequeue identically.
     */
    private function answer(CompletionRequest $request): string|\RuntimeException
    {
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
            // Proves the advancer read the account's per-config preference into the
            // request rather than hardcoding it (#323).
            'suppressReasoning' => $request->suppressReasoning,
        ];

        if (null !== $this->duringNextCall) {
            $hook = $this->duringNextCall;
            $this->duringNextCall = null;
            $hook();
        }

        if ([] === $this->queue) {
            throw new \LogicException('StubChatClient has no queued response left.');
        }

        return array_shift($this->queue);
    }
}
