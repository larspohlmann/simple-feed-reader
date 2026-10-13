<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\CrossFamilyFailover;
use App\Service\Fetch\FetchRetryPolicy;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An API's answer whatever its status, a redirect included; an error status another route may answer differently is
 * asked again over that route first, as FailoverRequestSender does for a blocking call.
 */
final readonly class ApiResponseExchange implements ResponseExchangeInterface
{
    public function __construct(private FetchRetryPolicy $retryPolicy, private CrossFamilyFailover $failover)
    {
    }

    public static function kind(): string
    {
        return ResponseExchangeKind::Api->value;
    }

    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel
    {
        if (!$this->failover->isRetryableStatus($response->getStatusCode())) {
            return ExchangeStepModel::awaitBody();
        }
        $nextRoute = $this->retryPolicy->nextRouteAfterErrorStatus($attempt);

        return null === $nextRoute ? ExchangeStepModel::awaitBody() : ExchangeStepModel::sendNext($nextRoute);
    }

    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(new ApiResponseModel(
            $response->getStatusCode(),
            $response->getContent(false),
            $response->getHeaders(false),
        ));
    }
}
