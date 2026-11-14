<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\ResponseExchange;

use App\Service\Outbound\Fetch\CrossFamilyFailover;
use App\Service\Outbound\Fetch\FetchRetryPolicy;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\ExchangeStepModel;
use App\Service\Outbound\Fetch\Model\FetchAttemptModel;
use App\Service\Outbound\Fetch\Model\FetchOutcomeModel;
use App\Service\Outbound\Fetch\Model\ResponseExchangeKind;
use App\Service\Outbound\Fetch\Support\ResponseBody;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An API's answer whatever its status, a redirect included; an error status another route may answer differently is
 * asked again over that route first, as FailoverRequestSender does for a blocking call.
 */
#[AsTaggedItem(index: ResponseExchangeKind::Api->value)]
final readonly class ApiResponseExchange implements ResponseExchangeInterface
{
    public function __construct(private FetchRetryPolicy $retryPolicy, private CrossFamilyFailover $failover)
    {
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
            ResponseBody::decoded($response, $attempt->ticket->exchange->sizeLimit()),
            $response->getHeaders(false),
        ));
    }
}
