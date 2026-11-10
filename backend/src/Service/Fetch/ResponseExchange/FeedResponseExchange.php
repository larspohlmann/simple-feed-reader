<?php

declare(strict_types=1);

namespace App\Service\Fetch\ResponseExchange;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Support\ResponseBody;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Fetch\Support\RetryAfterHeader;
use App\Service\Fetch\Support\UrlResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A feed's answer: a 304 settles it at the headers, a redirect goes back on the queue, a 2xx waits for its body.
 * SECURITY: every redirect target goes back through UrlGuard before the next request; the fetcher sends
 * `max_redirects` 0, so the client never follows one.
 */
final readonly class FeedResponseExchange implements ResponseExchangeInterface
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

    public static function kind(): string
    {
        return ResponseExchangeKind::Feed->value;
    }

    public function onHeaders(ResponseInterface $response, FetchAttemptModel $attempt): ExchangeStepModel
    {
        $status = $response->getStatusCode();

        if (\in_array($status, self::REDIRECT_CODES, true)) {
            return $this->redirect($response, $attempt, $status);
        }

        if (Response::HTTP_NOT_MODIFIED === $status) {
            return ExchangeStepModel::concluded(FetchOutcomeModel::succeeded($this->notModifiedOrEmptyFetch($attempt)));
        }

        if (Response::HTTP_GONE === $status) {
            throw new FeedGoneException(sprintf('%s: HTTP 410 Gone', $attempt->url));
        }

        if (FeedThrottledException::STATUS_CODE === $status) {
            throw new FeedThrottledException(
                sprintf('%s: HTTP 429', $attempt->url),
                $this->retryAfterSeconds($response),
            );
        }

        if ($status < Response::HTTP_OK || $status >= Response::HTTP_MULTIPLE_CHOICES) {
            throw new FeedUnreachableException(
                $attempt->url . FeedUnreachableException::STATUS_MARKER . $status,
                statusCode: $status,
            );
        }

        return ExchangeStepModel::awaitBody();
    }

    public function onBody(ResponseInterface $response, FetchAttemptModel $attempt): FetchOutcomeModel
    {
        $body = ResponseBody::decoded($response, ResponseSizeLimit::Feed);

        return FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
            $attempt->url,
            $attempt->permanentRedirect,
            $body,
            ResponseHeader::first($response, 'etag'),
            ResponseHeader::first($response, 'last-modified'),
        ));
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

    private function redirect(ResponseInterface $response, FetchAttemptModel $attempt, int $status): ExchangeStepModel
    {
        $location = ResponseHeader::first($response, 'location');
        if (null === $location) {
            throw new FeedUnreachableException(
                sprintf('%s: redirect without Location header', $attempt->url),
                statusCode: $status,
            );
        }

        if (!$attempt->canFollowRedirect()) {
            throw new FeedUnreachableException(sprintf(
                '%s: more than %d redirects',
                $attempt->ticket->request->url,
                FetchAttemptModel::MAX_REDIRECTS,
            ));
        }

        return ExchangeStepModel::sendNext($attempt->followedTo(
            UrlResolver::resolve($attempt->url, $location),
            \in_array($status, self::PERMANENT_CODES, true),
        ));
    }

    private function retryAfterSeconds(ResponseInterface $response): ?int
    {
        return RetryAfterHeader::secondsFrom(ResponseHeader::first($response, 'retry-after'), $this->clock->now());
    }
}
