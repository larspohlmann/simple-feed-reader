<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ApiBudgetModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** One JSON call to a public API, guarded and routed like a feed fetch. A redirect is an answer. */
final readonly class GuardedApiClient
{
    public const float MAX_DURATION_SECONDS = ApiBudgetModel::STANDARD_MAX_DURATION_SECONDS;

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
        $sizeLimit = $budget->sizeLimit;
        $guarded = $this->urlGuard->assertSafe($request->url);

        try {
            $response = $this->requestSender->send($request->method, $request->url, $guarded, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
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
            ]);

            return new ApiResponseModel(
                $response->getStatusCode(),
                $response->getContent(false),
                $response->getHeaders(false),
            );
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($request->url, $exception);
        }
    }
}
