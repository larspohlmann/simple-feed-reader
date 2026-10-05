<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\SystemOneClient;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Support\ResponseByteCap;
use App\Service\Ai\Support\RetryAfter;
use App\Service\Fetch\Support\ResponseHeader;
use App\Service\Recommendation\Jev\Model\SystemOneOutcomeModel;
use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;
use App\Service\Recommendation\Jev\Pass\SystemOneWave;
use App\Service\Recommendation\Jev\Support\RefusalMessage;
use App\Service\Recommendation\Jev\Support\SystemOneReplyDecoder;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends `POST {baseUrl}/systemone`. Its own timeouts, not the connection's slow-model pair: one request is bounded by
 * a 32k-token window. The caps are no SSRF boundary (docs/security.md#ai-provider-endpoints).
 */
final readonly class HttpSystemOneClient implements SystemOneClientInterface
{
    private const float IDLE_TIMEOUT_SECONDS = 120.0;
    private const float WALL_CLOCK_SECONDS = 300.0;

    /** The longest the wave waits without beating the tick's heartbeat, which keeps the per-user lock alive. */
    private const float HEARTBEAT_SECONDS = 10.0;

    private const array RETRYABLE_STATUSES = [429, 529];

    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ProviderCallHeartbeatInterface $heartbeat,
        private ClockInterface $clock,
        private string $userAgent,
    ) {
    }

    public function evaluateMany(ProviderCredentialsModel $credentials, array $requests): array
    {
        $wave = new SystemOneWave($this->clock, $credentials);
        foreach ($requests as $position => $request) {
            try {
                $wave->await($position, $this->send($credentials, $request));
            } catch (ExceptionInterface $exception) {
                $wave->settleAt($position, SystemOneOutcomeModel::failed(
                    ProviderUnreachableException::didNotAnswer($exception),
                ));
            }
        }

        while ([] !== $open = $wave->openResponses()) {
            $this->streamRound($wave, $open);
        }

        return $wave->outcomes();
    }

    /**
     * stream() drops a response after its timeout chunk, so each round re-streams the open ones; the round's timeout
     * only paces the heartbeat, and failSilentFor() is the idle bound.
     *
     * @param non-empty-list<ResponseInterface> $open
     */
    private function streamRound(SystemOneWave $wave, array $open): void
    {
        foreach ($this->httpClient->stream($open, self::HEARTBEAT_SECONDS) as $response => $chunk) {
            $this->heartbeat->beat();
            $outcome = $wave->isSettled($response) ? null : $this->outcomeAfter($wave, $response, $chunk);
            if (null !== $outcome) {
                $wave->settle($response, $outcome);
            }
            $wave->failSilentFor(self::IDLE_TIMEOUT_SECONDS);
        }
    }

    /** Null while the response is still arriving; a transport failure becomes this call's outcome. */
    private function outcomeAfter(
        SystemOneWave $wave,
        ResponseInterface $response,
        ChunkInterface $chunk,
    ): ?SystemOneOutcomeModel {
        try {
            if ($chunk->isTimeout()) {
                return null;
            }
            $wave->heardFrom($response);
            if ($chunk->isFirst()) {
                // Unread at the first chunk, stream() throws a 4xx/5xx status outside this call's outcome.
                $response->getStatusCode();
            }
            if (!$chunk->isLast()) {
                return null;
            }

            return $this->outcomeOf($wave, $response);
        } catch (ExceptionInterface $exception) {
            $response->cancel();

            return SystemOneOutcomeModel::failed(ProviderUnreachableException::didNotAnswer($exception));
        }
    }

    private function outcomeOf(SystemOneWave $wave, ResponseInterface $response): SystemOneOutcomeModel
    {
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        return match (true) {
            401 === $status, 403 === $status => SystemOneOutcomeModel::failed(
                CredentialsRejectedException::refusedKey(),
            ),
            \in_array($status, self::RETRYABLE_STATUSES, true) => SystemOneOutcomeModel::failed(
                new RetryableProviderException($status, RetryAfter::secondsIn($response)),
            ),
            400 === $status, 422 === $status => SystemOneOutcomeModel::failed(
                new ProviderUnreachableException($wave->withoutApiKey(RefusalMessage::of($status, $body))),
            ),
            $status >= 300 => SystemOneOutcomeModel::failed(ProviderUnreachableException::answeredWithStatus($status)),
            default => SystemOneOutcomeModel::answered(
                SystemOneReplyDecoder::decode($body, ResponseHeader::first($response, 'x-typesafe-request-id')),
            ),
        };
    }

    private function send(ProviderCredentialsModel $credentials, SystemOneRequestModel $request): ResponseInterface
    {
        return $this->httpClient->request('POST', $credentials->baseUrl . '/systemone', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // No transparent compression, so the size cap below also bounds the decompressed body.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
                ...$credentials->authorizationHeaders(),
            ],
            'body' => $request->toRequestBody(),
            'timeout' => self::IDLE_TIMEOUT_SECONDS,
            'max_duration' => self::WALL_CLOCK_SECONDS,
            'max_redirects' => 0,
            'on_progress' => ResponseByteCap::onProgress(self::MAXIMUM_RESPONSE_BYTES),
        ]);
    }
}
