<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
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
final class ResponseClassifier
{
    private const array REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const array PERMANENT_CODES = [301, 308];

    private readonly ClockInterface $clock;

    /**
     * The clock only ever reads a Retry-After date, so it defaults rather than
     * making every construction site of this stateless helper pass one.
     */
    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? Clock::get();
    }

    public function fromHeaders(ResponseInterface $response, FetchAttempt $attempt): HeaderVerdict
    {
        $status = $this->statusCode($response, $attempt->url);

        if (\in_array($status, self::REDIRECT_CODES, true)) {
            return $this->redirect($response, $attempt, $status);
        }

        if (304 === $status) {
            return HeaderVerdict::terminal(FetchResponse::notModified(
                $attempt->url,
                $attempt->permanentRedirect,
                $attempt->ticket->etag,
                $attempt->ticket->lastModified,
            ));
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

        return HeaderVerdict::awaitBody();
    }

    public function fromBody(ResponseInterface $response, FetchAttempt $attempt): FetchResponse
    {
        $body = $this->content($response, $attempt->url);
        ResponseTooLargeException::throwIfExceeded(\strlen($body), $attempt->url);

        return FetchResponse::fetched(
            $attempt->url,
            $attempt->permanentRedirect,
            $body,
            $this->header($response, 'etag'),
            $this->header($response, 'last-modified'),
        );
    }

    private function redirect(ResponseInterface $response, FetchAttempt $attempt, int $status): HeaderVerdict
    {
        $location = $this->header($response, 'location');
        if (null === $location) {
            throw new FeedUnreachableException(
                sprintf('%s: redirect without Location header', $attempt->url),
                statusCode: $status,
            );
        }

        $target = UrlResolver::resolve($attempt->url, $location);

        return \in_array($status, self::PERMANENT_CODES, true)
            ? HeaderVerdict::permanentRedirectTo($target)
            : HeaderVerdict::temporaryRedirectTo($target);
    }

    /**
     * The wait a Retry-After header asks for, in seconds. The header comes in
     * two shapes (RFC 9110): a delay, or the date the door reopens. Anything
     * else — and a date already in the past — names no delay, and the caller
     * falls back to its own retry window.
     */
    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = $this->header($response, 'retry-after');
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

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return null;
        }

        return $headers[$name][0] ?? null;
    }
}
