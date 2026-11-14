<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch;

use App\Service\Outbound\Fetch\Exception\DirectRouteUnavailableException;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Exception\ResponseTooLargeException;
use App\Service\Outbound\Fetch\Model\ApiBudgetModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Fetch\Model\ResponseSizeLimit;
use App\Service\Outbound\Fetch\Support\ResponseBody;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** One JSON call to a public API, guarded and routed like a feed fetch. A redirect is an answer. */
final readonly class GuardedApiClient
{
    public function __construct(
        private FailoverRequestSender $requestSender,
        private UrlGuard $urlGuard,
        private string $userAgent,
    ) {
    }

    /** @throws FetchException */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        return $this->sendWithin($request, ApiBudgetModel::standard());
    }

    /** @throws FetchException */
    public function sendWithin(ApiRequestModel $request, ApiBudgetModel $budget): ApiResponseModel
    {
        $guarded = $this->urlGuard->assertSafe($request->url);

        try {
            return self::answerFrom($this->requestSender->send(
                $request->method,
                $request->url,
                $guarded,
                $this->optionsFor($request, $budget),
            ), $budget->sizeLimit);
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($request->url, $exception);
        }
    }

    /**
     * Asks again on the direct route, once the caller has read the proxied answer as a refusal of the proxy's egress.
     *
     * @throws DirectRouteUnavailableException when no proxy is configured or its direct fallback is off
     * @throws FetchException
     */
    public function sendDirectAfterProxyRefusal(ApiRequestModel $request, ApiBudgetModel $budget): ApiResponseModel
    {
        $guarded = $this->urlGuard->assertSafe($request->url);

        try {
            return self::answerFrom($this->requestSender->sendDirectAfterProxyRefusal(
                $request->method,
                $request->url,
                $guarded,
                $this->optionsFor($request, $budget),
            ), $budget->sizeLimit);
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($request->url, $exception);
        }
    }

    /**
     * @throws ExceptionInterface
     * @throws FetchException     when the decoded body is too large or undecodable
     */
    private static function answerFrom(ResponseInterface $response, ResponseSizeLimit $limit): ApiResponseModel
    {
        return new ApiResponseModel(
            $response->getStatusCode(),
            ResponseBody::decoded($response, $limit),
            $response->getHeaders(false),
        );
    }

    /** @return array<string, mixed> */
    private function optionsFor(ApiRequestModel $request, ApiBudgetModel $budget): array
    {
        $sizeLimit = $budget->sizeLimit;

        return [
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Encoding' => ResponseBody::ACCEPT_ENCODING,
                'User-Agent' => $this->userAgent,
                ...$request->headers,
            ],
            'max_redirects' => 0,
            'timeout' => $budget->timeoutSeconds,
            'max_duration' => $budget->maxDurationSeconds,
            'on_progress' => static function (int $downloaded) use ($sizeLimit): void {
                ResponseTooLargeException::throwIfExceeded($sizeLimit, $downloaded);
            },
            ...(null === $request->json ? [] : ['json' => $request->json]),
            ...(null === $request->body ? [] : ['body' => $request->body]),
        ];
    }
}
