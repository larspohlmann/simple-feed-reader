<?php

declare(strict_types=1);

namespace App\Service\Version\LatestReleaseReader;

use App\Service\Version\Model\LatestReleaseModel;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A failure is cached briefly, so an outage neither costs every request the timeout nor pins the update badge shut
 * for the whole window. Every failure, and an empty repository, reads as null: the badge stays silent.
 */
final readonly class GitHubLatestReleaseReader implements LatestReleaseReaderInterface
{
    private const string CACHE_KEY = 'github_latest_release';
    private const int RELEASE_TTL_SECONDS = 60 * 60;
    private const int FAILURE_TTL_SECONDS = 5 * 60;
    private const float TIMEOUT_SECONDS = 5.0;
    private const int MAX_RESPONSE_BYTES = 524288; // 512 KiB — a release payload is far smaller

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $githubReleaseCache,
        private LoggerInterface $logger,
        private string $gitHubRepository,
        private string $userAgent,
    ) {
    }

    public function read(): ?LatestReleaseModel
    {
        if ('' === $this->gitHubRepository) {
            return null;
        }

        $item = $this->githubReleaseCache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            return self::cachedRelease($item);
        }

        return $this->remember($item, $this->fetch());
    }

    public function refresh(): ?LatestReleaseModel
    {
        if ('' === $this->gitHubRepository) {
            return null;
        }

        $latest = $this->fetch();
        $item = $this->githubReleaseCache->getItem(self::CACHE_KEY);
        if (null === $latest && $item->isHit()) {
            return self::cachedRelease($item);
        }

        return $this->remember($item, $latest);
    }

    private static function cachedRelease(CacheItemInterface $item): ?LatestReleaseModel
    {
        $cached = $item->get();

        return $cached instanceof LatestReleaseModel ? $cached : null;
    }

    private function remember(CacheItemInterface $item, ?LatestReleaseModel $latest): ?LatestReleaseModel
    {
        $item->set($latest);
        $item->expiresAfter(null === $latest ? self::FAILURE_TTL_SECONDS : self::RELEASE_TTL_SECONDS);
        $this->githubReleaseCache->save($item);

        return $latest;
    }

    private function fetch(): ?LatestReleaseModel
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

            if (Response::HTTP_OK !== $response->getStatusCode()) {
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
    private function toLatestRelease(array $payload): ?LatestReleaseModel
    {
        $tag = $payload['tag_name'] ?? null;
        $notesUrl = $payload['html_url'] ?? null;
        if (!is_string($tag) || '' === $tag || !is_string($notesUrl) || '' === $notesUrl) {
            return null;
        }

        return new LatestReleaseModel($tag, $notesUrl);
    }

    private function endpoint(): string
    {
        return sprintf('https://api.github.com/repos/%s/releases/latest', $this->gitHubRepository);
    }
}
