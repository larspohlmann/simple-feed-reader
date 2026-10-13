<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\HeaderVerdictModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Fetch\Support\RetryAfterHeader;
use App\Service\Fetch\Support\UrlResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What one HTTP response means for the feed that asked for it. SECURITY: every redirect target it returns goes back
 * through UrlGuard before the next request; the fetcher sends `max_redirects` 0, so the client never follows one.
 */
final readonly class ResponseClassifier
{
    private const array REDIRECT_CODES = [
        Response::HTTP_MOVED_PERMANENTLY,
        Response::HTTP_FOUND,
        Response::HTTP_SEE_OTHER,
        Response::HTTP_TEMPORARY_REDIRECT,
        Response::HTTP_PERMANENTLY_REDIRECT,
    ];
    private const array PERMANENT_CODES = [Response::HTTP_MOVED_PERMANENTLY, Response::HTTP_PERMANENTLY_REDIRECT];

    public function __construct(private ClockInterface $clock)
    {
    }

    public function fromHeaders(ResponseInterface $response, FetchAttemptModel $attempt): HeaderVerdictModel
    {
        $status = $this->statusCode($response, $attempt->url);

        if (\in_array($status, self::REDIRECT_CODES, true)) {
            return $this->redirect($response, $attempt, $status);
        }

        if (Response::HTTP_NOT_MODIFIED === $status) {
            return HeaderVerdictModel::terminal($this->notModifiedOrEmptyFetch($attempt));
        }

        if (Response::HTTP_GONE === $status) {
            throw new FeedGoneException(sprintf('%s: HTTP 410 Gone', $attempt->url));
        }

        if (Response::HTTP_TOO_MANY_REQUESTS === $status) {
            throw new FeedThrottledException(
                sprintf('%s: HTTP 429', $attempt->url),
                $this->retryAfterSeconds($response),
            );
        }

        if ($status < Response::HTTP_OK || $status >= Response::HTTP_MULTIPLE_CHOICES) {
            throw new FeedUnreachableException(
                sprintf('%s: HTTP %d', $attempt->url, $status),
                statusCode: $status,
            );
        }

        return HeaderVerdictModel::awaitBody();
    }

    public function fromBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchResponseModel
    {
        $body = $this->content($response, $attempt->url);
        ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Feed, \strlen($body), $attempt->url);

        return FetchResponseModel::fetched(
            $attempt->url,
            $attempt->permanentRedirect,
            $body,
            ResponseHeader::first($response, 'etag'),
            ResponseHeader::first($response, 'last-modified'),
        );
    }

    /** A 304 answering an unconditional request confirms nothing; it becomes an empty fetch instead. */
    private function notModifiedOrEmptyFetch(FetchAttemptModel $attempt): FetchResponseModel
    {
        $ticket = $attempt->ticket->feedTicket();
        if (!$ticket->isConditional()) {
            return FetchResponseModel::fetched($attempt->url, $attempt->permanentRedirect, '', null, null);
        }

        return FetchResponseModel::notModified(
            $attempt->url,
            $attempt->permanentRedirect,
            $ticket->etag,
            $ticket->lastModified,
        );
    }

    private function redirect(ResponseInterface $response, FetchAttemptModel $attempt, int $status): HeaderVerdictModel
    {
        $location = ResponseHeader::first($response, 'location');
        if (null === $location) {
            throw new FeedUnreachableException(
                sprintf('%s: redirect without Location header', $attempt->url),
                statusCode: $status,
            );
        }

        $target = UrlResolver::resolve($attempt->url, $location);

        return \in_array($status, self::PERMANENT_CODES, true)
            ? HeaderVerdictModel::permanentRedirectTo($target)
            : HeaderVerdictModel::temporaryRedirectTo($target);
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        return RetryAfterHeader::secondsFrom(ResponseHeader::first($response, 'retry-after'), $this->clock->now());
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($url, $exception);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($url, $exception);
        }
    }
}
