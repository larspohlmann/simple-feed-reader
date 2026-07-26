<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
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
    private const int MAX_BYTES = 5_000_000;
    private const array REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const array PERMANENT_CODES = [301, 308];

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
        if (\strlen($body) > self::MAX_BYTES) {
            throw new ResponseTooLargeException(
                sprintf('%s: response exceeds %d bytes', $attempt->url, self::MAX_BYTES),
            );
        }

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

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
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

    /**
     * The HTTP client wraps exceptions thrown inside on_progress; unwrap and
     * rethrow our size-limit exception so callers see the real cause.
     */
    private function rethrowTooLarge(?\Throwable $e): void
    {
        while (null !== $e) {
            if ($e instanceof ResponseTooLargeException) {
                throw $e;
            }
            $e = $e->getPrevious();
        }
    }
}
