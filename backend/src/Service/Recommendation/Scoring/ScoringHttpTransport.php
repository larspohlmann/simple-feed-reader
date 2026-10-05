<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\Support\RejectingStatus;
use App\Service\Ai\Support\ResponseByteCap;
use App\Service\Ai\Support\RetryAfter;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Scoring\Model\ScoringOutcomeModel;
use App\Service\Recommendation\Scoring\Pass\ResponseWave;
use App\Service\Recommendation\Scoring\Pass\ScoringEndpoint;
use App\Service\Recommendation\Support\ProviderErrorReason;
use App\Service\Recommendation\Support\RefusalMessage;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends a protocol's scoring requests at once and streams the replies. Its own timeouts, not the connection's
 * slow-model pair: one request is bounded by its model's window. The caps are no SSRF boundary
 * (docs/security.md#ai-provider-endpoints).
 */
final readonly class ScoringHttpTransport
{
    private const float IDLE_TIMEOUT_SECONDS = 120.0;
    private const float WALL_CLOCK_SECONDS = 300.0;

    /** The longest the wave waits without beating the tick's heartbeat, which keeps the per-user lock alive. */
    private const float HEARTBEAT_SECONDS = 10.0;

    private const int MAXIMUM_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ProviderCallHeartbeatInterface $heartbeat,
        private ClockInterface $clock,
        private string $userAgent,
    ) {
    }

    /**
     * One outcome per body, aligned by index. A per-call failure is carried in its outcome, never thrown, so it cannot
     * discard a sibling's answer.
     *
     * @param non-empty-list<string> $bodies
     *
     * @return list<ScoringOutcomeModel>
     */
    public function sendAll(ScoringEndpoint $endpoint, ProviderCredentialsModel $credentials, array $bodies): array
    {
        $wave = new ResponseWave($this->clock, $credentials, $endpoint);
        $url = $credentials->baseUrl . $endpoint->path;
        $authorizationHeaders = $credentials->authorizationHeaders();
        foreach ($bodies as $position => $body) {
            try {
                $wave->await($position, $this->send($url, $authorizationHeaders, $body));
            } catch (ExceptionInterface $exception) {
                $wave->settleAt($position, ScoringOutcomeModel::failed(
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
    private function streamRound(ResponseWave $wave, array $open): void
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
        ResponseWave $wave,
        ResponseInterface $response,
        ChunkInterface $chunk,
    ): ?ScoringOutcomeModel {
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

            return ScoringOutcomeModel::failed(ProviderUnreachableException::didNotAnswer($exception));
        }
    }

    private function outcomeOf(ResponseWave $wave, ResponseInterface $response): ScoringOutcomeModel
    {
        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        return match (true) {
            401 === $status, 403 === $status => ScoringOutcomeModel::failed(
                CredentialsRejectedException::refusedKey(),
            ),
            $wave->endpoint->retries($status) => ScoringOutcomeModel::failed(
                new RetryableProviderException($status, RetryAfter::secondsIn($response)),
            ),
            RejectingStatus::matches($status) => ScoringOutcomeModel::failed(new ProviderRejectedRequestException(
                $status,
                RefusalMessage::of($status, ProviderErrorReason::in($body, $wave->credentials)),
            )),
            $status >= 300 => ScoringOutcomeModel::failed(ProviderUnreachableException::answeredWithStatus($status)),
            default => ScoringOutcomeModel::answered($wave->endpoint->decode($body, $response)),
        };
    }

    /** @param array<string, string> $authorizationHeaders */
    private function send(string $url, array $authorizationHeaders, string $body): ResponseInterface
    {
        return $this->httpClient->request('POST', $url, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                // No transparent compression, so the size cap below also bounds the decompressed body.
                'Accept-Encoding' => 'identity',
                'User-Agent' => $this->userAgent,
                ...$authorizationHeaders,
            ],
            'body' => $body,
            'timeout' => self::IDLE_TIMEOUT_SECONDS,
            'max_duration' => self::WALL_CLOCK_SECONDS,
            'max_redirects' => 0,
            'on_progress' => ResponseByteCap::onProgress(self::MAXIMUM_RESPONSE_BYTES),
        ]);
    }
}
