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
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Decides what one HTTP response means for the feed that asked for it.
 *
 * SECURITY: this is the single copy of the redirect and status-code rules that
 * the SSRF guard depends on — every hop it returns is re-validated by UrlGuard
 * before the next request. A second implementation would drift out of step with
 * that guard, so both the serial and the concurrent fetcher route through here.
 */
final readonly class ResponseClassifier
{
    private const array REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const array PERMANENT_CODES = [301, 308];

    /**
     * Injected rather than read from the global clock: the only use is the
     * distance to a Retry-After date, and the tier that computes it is the one
     * observed running an hour fast — so it shares the refresh pipeline's
     * database clock (see config/services.yaml, RefreshClockWiringTest).
     */
    public function __construct(private ClockInterface $clock)
    {
    }

    public function fromHeaders(ResponseInterface $response, FetchAttemptModel $attempt): HeaderVerdictModel
    {
        $status = $this->statusCode($response, $attempt->url);

        if (\in_array($status, self::REDIRECT_CODES, true)) {
            return $this->redirect($response, $attempt, $status);
        }

        if (304 === $status) {
            return HeaderVerdictModel::terminal($this->notModifiedOrEmptyFetch($attempt));
        }

        if (410 === $status) {
            throw new FeedGoneException(sprintf('%s: HTTP 410 Gone', $attempt->url));
        }

        if (429 === $status) {
            throw new FeedThrottledException(
                sprintf('%s: HTTP 429', $attempt->url),
                $this->retryAfterSeconds($response),
            );
        }

        if ($status < 200 || $status >= 300) {
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
        ResponseTooLargeException::throwIfExceeded(\strlen($body), $attempt->url);

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
        $ticket = $attempt->ticket;
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

    /**
     * The wait a Retry-After header asks for, in seconds. The header comes in
     * two shapes (RFC 9110): a delay, or the date the door reopens. Anything
     * else — and a date already in the past — names no delay, and the caller
     * falls back to its own retry window.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = ResponseHeader::first($response, 'retry-after');
        if (null === $header) {
            return null;
        }

        $value = trim($header);
        if (1 === preg_match('/^\\d+$/', $value)) {
            return (int) $value;
        }

        $reopensAt = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value);
        if (false === $reopensAt) {
            return null;
        }

        $seconds = $reopensAt->getTimestamp() - $this->clock->now()->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw FetchException::from($url, $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw FetchException::from($url, $e);
        }
    }
}
