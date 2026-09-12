<?php

declare(strict_types=1);

namespace App\Service\Profiling;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PyroscopeClient
{
    private const float TIMEOUT_SECONDS = 1.0;
    private const string APPLICATION = 'simple-feed-reader';
    private const string SPY_NAME = 'excimer';

    // Never inject a logger here: a failed push must stay silent so a dead
    // Pyroscope can never reach a request or the worker.
    public function __construct(
        private HttpClientInterface $httpClient,
        private PyroscopeEndpoint $endpoint,
    ) {
    }

    public function push(CollapsedProfile $profile, ProfileLabels $labels): void
    {
        try {
            $pushUrl = $this->endpoint->pushUrl();
            if (null === $pushUrl) {
                return;
            }
            $this->httpClient->request('POST', rtrim($pushUrl, '/') . '/ingest', [
                'query' => [
                    'name' => $labels->toNameParameter(self::APPLICATION),
                    'from' => (string) $profile->startedAtUnix,
                    'until' => (string) $profile->endedAtUnix,
                    'sampleRate' => (string) $profile->sampleRateHz,
                    'spyName' => self::SPY_NAME,
                ],
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => $profile->collapsedStacks,
                'timeout' => self::TIMEOUT_SECONDS,
            ])->getStatusCode();
        } catch (\Throwable) {
            // fail-open
        }
    }
}
