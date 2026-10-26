<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Exception\DirectRouteUnavailableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiBudgetModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\ResponseSizeLimit;

/** One budgeted, size-capped request to a description provider (#1824). */
final readonly class GuardedDescriptionRequest
{
    public const float BUDGET_SECONDS = 3.0;

    public function __construct(private GuardedApiClient $api)
    {
    }

    /** @return array<mixed> */
    public function answerOf(ApiRequestModel $request, EmbedAddressModel $embed): array
    {
        return $this->answerOver($this->api->sendWithin(...), $request, $embed);
    }

    /**
     * Asks again directly, after the provider refused to answer the egress proxy.
     *
     * @return array<mixed>
     *
     * @throws DirectRouteUnavailableException when no proxy is configured or its direct fallback is off
     */
    public function directAnswerOf(ApiRequestModel $request, EmbedAddressModel $embed): array
    {
        return $this->answerOver($this->api->sendDirectAfterProxyRefusal(...), $request, $embed);
    }

    /**
     * @param \Closure(ApiRequestModel, ApiBudgetModel): ApiResponseModel $route
     *
     * @return array<mixed>
     */
    private function answerOver(\Closure $route, ApiRequestModel $request, EmbedAddressModel $embed): array
    {
        try {
            $response = $route(
                $request,
                new ApiBudgetModel(self::BUDGET_SECONDS, self::BUDGET_SECONDS, ResponseSizeLimit::EmbedDescription),
            );
        } catch (SsrfBlockedException | ResponseTooLargeException) {
            return [];
        } catch (FetchException $exception) {
            throw EmbedDescriptionUnreachableException::from($embed, $exception);
        }
        if ($response->isTransientFailure()) {
            throw EmbedDescriptionUnreachableException::answered($embed, $response->status);
        }

        return $response->isSuccessful() ? $response->json() : [];
    }
}
