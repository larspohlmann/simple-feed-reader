<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** One JSON call to a public API, guarded and routed like a feed fetch. A redirect is an answer. */
final readonly class GuardedApiClient
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private FailoverRequestSender $requestSender,
        private UrlGuard $urlGuard,
        private string $userAgent,
    ) {
    }

    /** @throws FetchException */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
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
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'on_progress' => static function (int $downloaded): void {
                    ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Api, $downloaded);
                },
                ...(null === $request->json ? [] : ['json' => $request->json]),
            ]);

            return new ApiResponseModel($response->getStatusCode(), $response->getContent(false));
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($request->url, $exception);
        }
    }
}
