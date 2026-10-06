<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\ScoringProtocol;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Scoring\SystemOneClient\SystemOneClientInterface;
use App\Service\Recommendation\Support\TokenEstimate;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** TypeSafe's System One: the reader once per request in `state`, one `noul` question per article. */
#[AsTaggedItem(index: ScoringProtocol::SystemOne->value)]
final readonly class SystemOneProtocol implements ScoringProtocolInterface
{
    /** Cloudflare's Clef refuses more, the smallest cap measured (#1394); every System One model takes 64. */
    public const int QUESTIONS_PER_REQUEST = 64;

    public function __construct(
        private ScoringBatchPacker $packer,
        private SystemOneRequestFactory $requestFactory,
        private SystemOneClientInterface $client,
    ) {
    }

    public function budget(int $contextWindowTokens): ScoringBudgetModel
    {
        return ScoringBudgetModel::forWindow($contextWindowTokens, self::QUESTIONS_PER_REQUEST);
    }

    public function pack(ScoringBudgetModel $budget, array $candidates): array
    {
        return $this->packer->pack($candidates, $budget, $this->questionTokens(...));
    }

    public function renderedRequest(ScoringRequestModel $request): string
    {
        return $this->requestFactory->create($request)->toRenderedRequest();
    }

    public function scoreMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return $this->client->evaluateMany($credentials, array_map($this->requestFactory->create(...), $requests));
    }

    private function questionTokens(ArticleLineModel $candidate): int
    {
        return TokenEstimate::of(CompactJson::encode($this->requestFactory->question($candidate)));
    }
}
