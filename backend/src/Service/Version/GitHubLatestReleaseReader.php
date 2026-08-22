<?php

declare(strict_types=1);

namespace App\Service\Version;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the newest published release from GitHub and holds it for a while, so
 * "is there a newer version?" costs one request per cache window however many
 * users ask. Every failure mode — the source unreachable, rate-limited, no
 * release cut yet, or the check switched off with an empty repository — reads
 * as null rather than an error: the badge simply stays silent.
 *
 * Only a success is cached. A transient failure is not "the answer", so it must
 * not pin the badge shut for the whole window; the next request retries.
 */
final readonly class GitHubLatestReleaseReader implements LatestReleaseReader
{
    private const string CACHE_KEY = 'github_latest_release';
    private const int CACHE_TTL_SECONDS = 21600; // six hours
    private const float TIMEOUT_SECONDS = 5.0;
    private const int MAX_RESPONSE_BYTES = 524288; // 512 KiB — a release payload is far smaller

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $githubReleaseCache,
        private LoggerInterface $logger,
        private string $repository,
        private string $userAgent,
    ) {
    }

    public function read(): ?LatestRelease
    {
        if ('' === $this->repository) {
            return null;
        }

        $item = $this->githubReleaseCache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();

            return $cached instanceof LatestRelease ? $cached : null;
        }

        $latest = $this->fetch();
        if (null !== $latest) {
            $item->set($latest);
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->githubReleaseCache->save($item);
        }

        return $latest;
    }

    private function fetch(): ?LatestRelease
    {
        try {
            $response = $this->httpClient->request('GET', $this->endpoint(), [
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => $this->userAgent,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 3,
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_RESPONSE_BYTES) {
                        throw new TransportException('GitHub release payload exceeded the size cap.');
                    }
                },
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            return $this->toLatestRelease($response->toArray());
        } catch (ExceptionInterface $error) {
            $this->logger->warning('Could not read the latest GitHub release.', ['exception' => $error]);

            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function toLatestRelease(array $payload): ?LatestRelease
    {
        $tag = $payload['tag_name'] ?? null;
        $notesUrl = $payload['html_url'] ?? null;
        if (!is_string($tag) || '' === $tag || !is_string($notesUrl) || '' === $notesUrl) {
            return null;
        }

        return new LatestRelease($tag, $notesUrl);
    }

    private function endpoint(): string
    {
        return sprintf('https://api.github.com/repos/%s/releases/latest', $this->repository);
    }
}
