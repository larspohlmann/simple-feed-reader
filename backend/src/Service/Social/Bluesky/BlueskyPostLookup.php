<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\BlueskySessions;
use App\Service\Social\BlueskyPost\AppViewClient;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;

final readonly class BlueskyPostLookup
{
    private const string GET_POSTS = '/xrpc/app.bsky.feed.getPosts?';

    public function __construct(private BlueskySessions $sessions)
    {
    }

    /**
     * @param list<string> $uris
     *
     * @return array<string, JsonNodeModel> post by AT URI
     *
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function byUri(SocialAccount $account, array $uris): array
    {
        $posts = [];
        foreach (array_chunk($uris, AppViewClient::URIS_PER_CALL) as $chunk) {
            $query = implode('&', array_map(static fn (string $uri): string => 'uris=' . rawurlencode($uri), $chunk));
            $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_POSTS . $query));
            foreach (JsonNodeModel::fromValue($answer->json())->nodes('posts') as $post) {
                $uri = $post->string('uri');
                if ($uri !== null) {
                    $posts[$uri] = $post;
                }
            }
        }

        return $posts;
    }
}
