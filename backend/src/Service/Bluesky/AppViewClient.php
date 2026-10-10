<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;

/** Bluesky's public AppView, asked anonymously through the SSRF-guarded fetcher. */
final readonly class AppViewClient
{
    public const int URIS_PER_CALL = 25;

    private const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts';

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private HostThrottle $hostThrottle,
    ) {
    }

    public function isThrottled(): bool
    {
        return $this->hostThrottle->remainingSeconds(self::GET_POSTS) > 0;
    }

    /**
     * @param list<string> $uris at most URIS_PER_CALL post URIs
     *
     * @return array<string, JsonNodeModel> the post views by URI; a deleted or hidden post is absent
     *
     * @throws FetchException
     * @throws AppViewAnswerException
     */
    public function posts(array $uris): array
    {
        try {
            $body = $this->fetcher->fetch(self::postsUrl($uris))->modifiedBody();
        } catch (FeedThrottledException $exception) {
            $this->hostThrottle->record(self::GET_POSTS, $exception->retryAfterSeconds);

            throw $exception;
        }

        return self::byUri($body);
    }

    /** @param list<string> $uris */
    private static function postsUrl(array $uris): string
    {
        $query = array_map(static fn (string $uri): string => 'uris=' . rawurlencode($uri), $uris);

        return self::GET_POSTS . '?' . implode('&', $query);
    }

    /**
     * @return array<string, JsonNodeModel>
     *
     * @throws AppViewAnswerException
     */
    private static function byUri(string $body): array
    {
        $answer = json_decode($body, true);
        $views = \is_array($answer) ? $answer['posts'] ?? null : null;
        if (!\is_array($views) || !array_is_list($views)) {
            throw new AppViewAnswerException('The AppView answered without a list of posts.');
        }

        $posts = [];
        foreach ($views as $view) {
            $post = JsonNodeModel::of($view);
            $uri = $post->string('uri');
            if ($uri !== null) {
                $posts[$uri] = $post;
            }
        }

        return $posts;
    }
}
