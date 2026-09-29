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
 * The test container's ChatCompletionClientInterface: records every call and answers from one FIFO queue, so a queued
 * "fail, then succeed" keeps that order whichever queue* method ran first.
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

    /** Runs inside the next complete(), before it answers: the provider call is where a tick can change underneath. */
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

        // A spoiled reply is content, not an exception: the real client returns it for the caller's parser to judge.
        if ($next instanceof ProviderReplyFailureExceptionInterface) {
            return $next->partialAnswer();
        }

        if ($next instanceof \RuntimeException) {
            throw $next;
        }

        return $next;
    }

    /**
     * Answers from complete()'s queue, but folds a queued failure into that call's outcome instead of throwing: one
     * failed call never aborts its siblings. Outcomes align with $calls by index.
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
            // Proves each phase asked for its own structured shape (ranking, duplicate list) rather than sharing one.
            'responseSchemaName' => $request->responseSchema->name,
            // Proves the connection's per-config preference reached the request.
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
