<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\RedirectChainException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Follows a GET to the first response that is not a redirect, passing every hop
 * through the SSRF guard: `max_redirects` is forced to 0, so the client can never
 * follow a Location this class has not checked. The reader's page fetch and its
 * stream locator share it; the feed engine keeps ResponseClassifier, which is
 * built around FetchAttempt.
 */
final readonly class RedirectFollower
{
    private const array REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private FailoverRequestSender $requestSender,
        private UrlGuard $urlGuard,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws RedirectChainException on a blocked hop, a transport failure, a
     *                                  redirect without Location, or too many hops
     */
    public function follow(string $url, array $options, int $maxRedirects): LandedResponse
    {
        $currentUrl = $url;
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $response = $this->send($currentUrl, $options);
            $status = $this->statusCode($response, $currentUrl);
            if (!\in_array($status, self::REDIRECT_STATUSES, true)) {
                return new LandedResponse($currentUrl, $status, $response);
            }
            $currentUrl = $this->redirectTarget($response, $currentUrl);
        }

        throw new RedirectChainException(sprintf('%s: more than %d redirects', $url, $maxRedirects));
    }

    /** @param array<string, mixed> $options */
    private function send(string $url, array $options): ResponseInterface
    {
        try {
            $guarded = $this->urlGuard->assertSafe($url);

            return $this->requestSender->send('GET', $url, $guarded, ['max_redirects' => 0] + $options);
        } catch (FetchException | ExceptionInterface $e) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function redirectTarget(ResponseInterface $response, string $url): string
    {
        $location = $this->header($response, 'location');
        $response->cancel();
        if ($location === null) {
            throw new RedirectChainException(sprintf('%s: redirect without Location', $url));
        }

        try {
            return UrlResolver::resolve($url, $location);
        } catch (FetchException $e) {
            throw new RedirectChainException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            return $response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
