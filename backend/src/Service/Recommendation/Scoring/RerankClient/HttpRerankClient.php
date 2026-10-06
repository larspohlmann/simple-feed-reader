<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\RerankClient;

use App\Enum\ScoringProtocol;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Scoring\ScoringHttpTransport;
use App\Service\Recommendation\Scoring\Support\RerankReplyDecoder;
use App\Service\Recommendation\Support\CompactJson;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Sends `POST {baseUrl}/rerank` through the scoring transport. */
final readonly class HttpRerankClient implements RerankClientInterface
{
    public function __construct(private ScoringHttpTransport $transport)
    {
    }

    public function rerankMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return $this->transport->sendAll(
            new ScoringEndpoint(
                ScoringProtocol::Rerank->path(),
                static fn (string $body, ResponseInterface $response, int $position): ScoringReplyModel
                    => RerankReplyDecoder::decode($body, $requests[$position]->entryIds()),
            ),
            $credentials,
            array_map(
                static fn (RerankRequestModel $request): string => CompactJson::encode($request->payload()),
                $requests,
            ),
        );
    }
}
