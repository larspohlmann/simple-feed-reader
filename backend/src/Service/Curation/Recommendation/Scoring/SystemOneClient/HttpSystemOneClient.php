<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Scoring\SystemOneClient;

use App\Enum\ScoringProtocol;
use App\Service\Curation\Ai\Model\ProviderCredentialsModel;
use App\Service\Outbound\Fetch\Support\ResponseHeader;
use App\Service\Curation\Recommendation\Scoring\Model\ScoringReplyModel;
use App\Service\Curation\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Curation\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Curation\Recommendation\Scoring\ScoringHttpTransport;
use App\Service\Curation\Recommendation\Scoring\Support\SystemOneReplyDecoder;
use App\Service\Curation\Recommendation\Support\CompactJson;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Sends `POST {baseUrl}/systemone` through the scoring transport. */
final readonly class HttpSystemOneClient implements SystemOneClientInterface
{
    private const string REQUEST_ID_HEADER = 'x-typesafe-request-id';

    public function __construct(private ScoringHttpTransport $transport)
    {
    }

    public function evaluateMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        return $this->transport->sendAll(
            new ScoringEndpoint(ScoringProtocol::SystemOne->path(), self::decode(...)),
            $credentials,
            array_map(
                static fn (SystemOneRequestModel $request): string => CompactJson::encode($request->payload()),
                $requests,
            ),
        );
    }

    private static function decode(string $body, ResponseInterface $response): ScoringReplyModel
    {
        return SystemOneReplyDecoder::decode($body, ResponseHeader::first($response, self::REQUEST_ID_HEADER));
    }
}
