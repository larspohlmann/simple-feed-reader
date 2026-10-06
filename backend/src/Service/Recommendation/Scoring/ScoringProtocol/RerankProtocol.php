<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\ScoringProtocol;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\RerankRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\RerankClient\RerankClientInterface;
use App\Service\Recommendation\Support\PrettyJson;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A reranker: the reader as the query, one document per article, each ranked by its relevance to the query.
 *
 * @implements ScoringProtocolInterface<RerankRequestModel>
 */
#[AsTaggedItem(index: ScoringProtocol::Rerank->value)]
final readonly class RerankProtocol implements ScoringProtocolInterface
{
    public const int DOCUMENTS_PER_REQUEST = 100;

    public function __construct(
        private RerankRequestFactory $requestFactory,
        private RerankClientInterface $client,
    ) {
    }

    public function budget(int $contextWindowTokens): ScoringBudgetModel
    {
        return ScoringBudgetModel::forWindow($contextWindowTokens, self::DOCUMENTS_PER_REQUEST);
    }

    public function pack(ScoringBudgetModel $budget, array $candidates): array
    {
        return array_chunk(
            array_map(static fn (ArticleLineModel $candidate): int => $candidate->entryId, $candidates),
            $budget->maxItemsPerRequest,
        );
    }

    public function word(ScoringRequestModel $request): RerankRequestModel
    {
        return $this->requestFactory->create($request);
    }

    public function renderedRequest(object $request): string
    {
        return PrettyJson::of($request->payload());
    }

    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return $this->client->rerankMany($credentials, $requests);
    }
}
