<?php

declare(strict_types=1);

namespace App\Service\Logging\Loki;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Never inject a logger here: this runs inside the logging pipeline, and a log
 * call would recurse. Every failure is swallowed so a dead Loki cannot reach a
 * request.
 */
final readonly class LokiClient
{
    private const float TIMEOUT_SECONDS = 1.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LokiEndpoint $endpoint,
    ) {
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     */
    public function push(array $lines): void
    {
        $url = $this->endpoint->pushUrl();
        if (null === $url || [] === $lines) {
            return;
        }

        try {
            $this->httpClient->request('POST', $url, $this->options($lines))->getStatusCode();
        } catch (\Throwable) {
            // fail-open: logging must never break the request
        }
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     *
     * @return array<string, mixed>
     */
    private function options(array $lines): array
    {
        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['streams' => $this->streams($lines)], JSON_THROW_ON_ERROR),
        ];

        $username = $this->endpoint->username();
        $token = $this->endpoint->token();
        if (null !== $username && null !== $token) {
            $options['auth_basic'] = [$username, $token];
        }

        return $options;
    }

    /**
     * @param list<array{ts: string, line: string, labels: array<string, string>}> $lines
     *
     * @return list<array{stream: array<string, string>, values: list<array{0: string, 1: string}>}>
     */
    private function streams(array $lines): array
    {
        $grouped = [];
        foreach ($lines as $entry) {
            $key = json_encode($entry['labels'], JSON_THROW_ON_ERROR);
            $grouped[$key]['stream'] = $entry['labels'];
            $grouped[$key]['values'][] = [$entry['ts'], $entry['line']];
        }

        return array_values($grouped);
    }
}
