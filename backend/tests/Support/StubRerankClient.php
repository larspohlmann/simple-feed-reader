<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\RerankClient\RerankClientInterface;
use App\Service\Recommendation\Scoring\Support\RerankReplyDecoder;

/**
 * The test container's RerankClientInterface: records every request and answers each from one FIFO queue, with replies
 * shaped and decoded like OpenRouter's, best result first.
 */
final class StubRerankClient implements RerankClientInterface
{
    public const string REQUEST_ID = 'gen-rerank-1759740000-stub';
    public const string ANSWERING_MODEL = 'cohere/rerank-4-fast-20260901';
    public const int TOTAL_TOKENS = 900;
    public const int COST_NANO_CREDITS = 2_000_000;

    /** @var list<\Closure(RerankRequestModel): ScoringOutcomeModel> */
    private array $queue = [];

    /** @var list<RerankRequestModel> */
    private array $requests = [];

    /** @param \Closure(int): float $relevances each document's relevance, by the candidate's entry id */
    public function queueRelevances(\Closure $relevances): void
    {
        $this->queue[] = static fn (RerankRequestModel $request): ScoringOutcomeModel => ScoringOutcomeModel::answered(
            RerankReplyDecoder::decode(self::replyBody($request, $relevances), $request->entryIds()),
        );
    }

    /** @return list<RerankRequestModel> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function rerankMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return array_map(function (RerankRequestModel $request): ScoringOutcomeModel {
            $this->requests[] = $request;
            $script = array_shift($this->queue) ?? throw new \LogicException('No rerank reply is queued.');

            return $script($request);
        }, $requests);
    }

    /** @param \Closure(int): float $relevances */
    private static function replyBody(RerankRequestModel $request, \Closure $relevances): string
    {
        $results = [];
        foreach ($request->entryIds() as $index => $entryId) {
            $results[] = [
                'index' => $index,
                'relevance_score' => $relevances($entryId),
                'document' => ['text' => $request->documents[$entryId]],
            ];
        }
        usort($results, static fn (array $left, array $right): int
            => $right['relevance_score'] <=> $left['relevance_score']);

        return json_encode([
            'id' => self::REQUEST_ID,
            'model' => self::ANSWERING_MODEL,
            'provider' => 'Cohere',
            'results' => $results,
            'usage' => ['total_tokens' => self::TOTAL_TOKENS, 'cost' => 0.002],
        ], \JSON_THROW_ON_ERROR);
    }
}
