<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Completion\ChatCompletionClient\ChatCompletionClientInterface;
use App\Service\Ai\Completion\CompletionStreamObserver\CompletionStreamObserverInterface;
use App\Service\Ai\Completion\Model\CompletionOutcomeModel;
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Ai\Exception\ProviderReplyFailureExceptionInterface;
use App\Service\Ai\Model\ProviderConnectionModel;

/**
 * Records every complete() call and answers with a queued response, so
 * recommendation tests can assert exactly which prompts reached the model
 * without a live provider call. Registered as the container's
 * ChatCompletionClientInterface in the test environment (services_test.yaml), so it
 * stands in wherever the production alias would resolve to
 * OpenAiCompatibleChatClient.
 *
 * Content and failures share one FIFO queue rather than two, so a test that
 * queues "fail, then succeed" (to prove a corrective retry) gets that exact
 * order regardless of which queue* method it called first.
 */
final class StubChatClient implements ChatCompletionClientInterface
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

    public function queueFailure(\RuntimeException $exception): void
    {
        $this->queue[] = $exception;
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
        ProviderConnectionModel $connection,
        CompletionRequestModel $request,
        CompletionStreamObserverInterface $observer,
    ): string {
        $next = $this->answer($request);

        // A spoiled reply is content, not an exception — the real client
        // returns it so the caller's parser can judge it (#437).
        if ($next instanceof ProviderReplyFailureExceptionInterface) {
            return $next->partialAnswer();
        }

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
    public function completeMany(ProviderConnectionModel $connection, array $calls): array
    {
        $outcomes = [];

        foreach ($calls as $call) {
            $next = $this->answer($call->request);
            $outcomes[] = match (true) {
                $next instanceof ProviderReplyFailureExceptionInterface => CompletionOutcomeModel::unusableReply($next),
                $next instanceof \RuntimeException => CompletionOutcomeModel::failure($next),
                default => CompletionOutcomeModel::answer($next),
            };
        }

        return $outcomes;
    }

    /**
     * Records the prompt, runs the one-shot hook, and returns the next queued
     * response — a string answer or the failure to surface. Shared by both
     * read methods so they record and dequeue identically.
     */
    private function answer(CompletionRequestModel $request): string|\RuntimeException
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
            // Proves the connection's per-config preference reached the request (#323).
            'suppressReasoning' => Reasoning::Suppressed === $request->reasoning,
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
