<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\RedirectChainException;
use App\Service\Fetch\Pass\LandedResponse;
use App\Service\Fetch\Pass\RedirectBudget;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Fetch\Support\UrlResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Follows a GET to the first response that is not a redirect, passing every hop through the SSRF guard:
 * `max_redirects` is forced to 0, so the client never follows a Location this class has not checked.
 */
final readonly class RedirectFollower
{
    private const array REDIRECT_STATUSES = [
        Response::HTTP_MOVED_PERMANENTLY,
        Response::HTTP_FOUND,
        Response::HTTP_SEE_OTHER,
        Response::HTTP_TEMPORARY_REDIRECT,
        Response::HTTP_PERMANENTLY_REDIRECT,
    ];

    public function __construct(
        private FailoverRequestSender $requestSender,
        private UrlGuard $urlGuard,
    ) {
    }

    /**
     * Each hop spends the budget: one redirect per Location followed, and its seconds, so a hop's max_duration is what
     * the hops before it left and a chain of slow redirects cannot outlast the budget.
     *
     * @param array<string, mixed> $options
     *
     * @throws RedirectChainException on a blocked hop, a transport failure, a redirect without Location, too many
     *                                redirects, or when the time runs out before a hop
     */
    public function follow(string $url, array $options, RedirectBudget $budget): LandedResponse
    {
        $currentUrl = $url;
        while (true) {
            self::assertTimeLeft($url, $budget);
            $response = $this->send($currentUrl, self::withinBudget($options, $budget));
            $status = $this->statusCode($response, $currentUrl);
            $budget->spendSeconds(self::secondsTaken($response));
            if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
                return new LandedResponse($currentUrl, $status, $response);
            }
            $currentUrl = $this->redirectTarget($response, $currentUrl);
            self::spendRedirect($url, $budget);
        }
    }

    private static function assertTimeLeft(string $url, RedirectBudget $budget): void
    {
        if ($budget->isOutOfTime()) {
            throw new RedirectChainException(sprintf('%s: out of time after %d hops', $url, $budget->redirectsTaken()));
        }
    }

    private static function spendRedirect(string $url, RedirectBudget $budget): void
    {
        if (!$budget->takeRedirect()) {
            throw new RedirectChainException(sprintf('%s: more than %d redirects', $url, $budget->maxRedirects));
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function withinBudget(array $options, RedirectBudget $budget): array
    {
        $remaining = $budget->remainingSeconds();

        return is_infinite($remaining) ? $options : ['max_duration' => $remaining] + $options;
    }

    private static function secondsTaken(ResponseInterface $response): float
    {
        $totalTime = $response->getInfo('total_time');

        return is_numeric($totalTime) ? (float) $totalTime : 0.0;
    }

    /** @param array<string, mixed> $options */
    private function send(string $url, array $options): ResponseInterface
    {
        try {
            $guarded = $this->urlGuard->assertSafe($url);

            return $this->requestSender->send('GET', $url, $guarded, ['max_redirects' => 0] + $options);
        } catch (FetchException | ExceptionInterface $exception) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $exception->getMessage()), previous: $exception);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $exception) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $exception->getMessage()), previous: $exception);
        }
    }

    private function redirectTarget(ResponseInterface $response, string $url): string
    {
        $location = ResponseHeader::first($response, 'location');
        $response->cancel();
        if ($location === null) {
            throw new RedirectChainException(sprintf('%s: redirect without Location', $url));
        }

        try {
            return UrlResolver::resolve($url, $location);
        } catch (FetchException $exception) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $exception->getMessage()), previous: $exception);
        }
    }
}
