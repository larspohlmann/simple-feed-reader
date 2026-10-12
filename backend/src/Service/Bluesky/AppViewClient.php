<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;

/** Bluesky's public AppView, asked anonymously through the SSRF-guarded fetcher. */
final readonly class AppViewClient
{
    public const int URIS_PER_CALL = 25;
    public const string BASE_URL = 'https://public.api.bsky.app/xrpc/';

    private const string GET_POSTS = self::BASE_URL . 'app.bsky.feed.getPosts';
    private const string GET_PROFILES = self::BASE_URL . 'app.bsky.actor.getProfiles';
    private const int UNREACHABLE_BACKOFF_SECONDS = 300;
    private const int FIRST_CLIENT_ERROR = 400;
    private const int LAST_CLIENT_ERROR = 499;

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
        $posts = [];
        foreach ($this->views(self::listUrl(self::GET_POSTS, 'uris', $uris), 'posts') as $post) {
            $uri = $post->string('uri');
            if ($uri !== null) {
                $posts[$uri] = $post;
            }
        }

        return $posts;
    }

    /**
     * @param list<string> $actors at most URIS_PER_CALL DIDs or handles
     *
     * @return array<string, JsonNodeModel> the profile views by DID and lower-cased handle; an unknown actor is absent
     *
     * @throws FetchException
     * @throws AppViewAnswerException
     */
    public function profiles(array $actors): array
    {
        $profiles = [];
        foreach ($this->views(self::listUrl(self::GET_PROFILES, 'actors', $actors), 'profiles') as $profile) {
            $did = $profile->string('did');
            if ($did === null) {
                continue;
            }
            $profiles[$did] = $profile;
            $handle = $profile->string('handle');
            if ($handle !== null) {
                $profiles[strtolower($handle)] = $profile;
            }
        }

        return $profiles;
    }

    /**
     * @return list<JsonNodeModel>
     *
     * @throws FetchException
     * @throws AppViewAnswerException
     */
    private function views(string $url, string $field): array
    {
        try {
            $body = $this->fetcher->fetch($url)->modifiedBody();
        } catch (FeedThrottledException $exception) {
            $this->hostThrottle->record($url, $exception->retryAfterSeconds);

            throw $exception;
        } catch (FetchException $exception) {
            if (!self::rejectedTheRequest($exception)) {
                $this->hostThrottle->record($url, self::UNREACHABLE_BACKOFF_SECONDS);
            }

            throw $exception;
        }

        return self::listIn($body, $field);
    }

    private static function rejectedTheRequest(FetchException $failure): bool
    {
        $status = $failure instanceof FeedUnreachableException ? $failure->statusCode : null;

        return $failure instanceof FeedGoneException
            || ($status !== null && $status >= self::FIRST_CLIENT_ERROR && $status <= self::LAST_CLIENT_ERROR);
    }

    /** @param list<string> $values */
    private static function listUrl(string $endpoint, string $parameter, array $values): string
    {
        $query = array_map(static fn (string $value): string => $parameter . '=' . rawurlencode($value), $values);

        return $endpoint . '?' . implode('&', $query);
    }

    /**
     * @return list<JsonNodeModel>
     *
     * @throws AppViewAnswerException
     */
    private static function listIn(string $body, string $field): array
    {
        $answer = json_decode($body, true);
        $views = \is_array($answer) ? $answer[$field] ?? null : null;
        if (!\is_array($views) || !array_is_list($views)) {
            throw new AppViewAnswerException(sprintf('The AppView answered without a list of %s.', $field));
        }

        return array_map(JsonNodeModel::of(...), $views);
    }
}
