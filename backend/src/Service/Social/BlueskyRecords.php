<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyStrongRef;

/** Writes and deletes records in an account's own repo: likes and reposts of a post, and its own posts. */
final readonly class BlueskyRecords
{
    public const string POST_COLLECTION = 'app.bsky.feed.post';
    private const string CREATE_RECORD = '/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = '/xrpc/com.atproto.repo.deleteRecord';

    public function __construct(
        private BlueskySessions $sessions,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return string|null the AT URI Bluesky gave the new record */
    public function createAbout(SocialPostModel $post, string $collection): ?string
    {
        $answer = $this->sessions->actAs($post->account, self::CREATE_RECORD, [
            'repo' => $post->account->getIdentity(),
            'collection' => $collection,
            'record' => [
                '$type' => $collection,
                'subject' => BlueskyStrongRef::of($post),
                'createdAt' => $this->now(),
            ],
        ]);

        return JsonNodeModel::of($answer->json())->string('uri');
    }

    /**
     * @param array<string, mixed> $fields the post record beyond its type and creation time
     *
     * @return string the AT URI Bluesky gave the new post
     *
     * @throws SocialNetworkUnreachableException when Bluesky answers without one
     */
    public function createPost(SocialAccount $account, array $fields): string
    {
        $answer = $this->sessions->actAs($account, self::CREATE_RECORD, [
            'repo' => $account->getIdentity(),
            'collection' => self::POST_COLLECTION,
            'record' => ['$type' => self::POST_COLLECTION, ...$fields, 'createdAt' => $this->now()],
        ]);

        return JsonNodeModel::of($answer->json())->string('uri')
            ?? throw new SocialNetworkUnreachableException('Bluesky took the post but named no address for it.');
    }

    /** Bluesky answers a missing record without a commit, or with a 404. */
    public function delete(SocialAccount $account, string $collection, string $recordKey): PostDeletion
    {
        try {
            $answer = $this->sessions->actAs($account, self::DELETE_RECORD, [
                'repo' => $account->getIdentity(),
                'collection' => $collection,
                'rkey' => $recordKey,
            ]);
        } catch (SocialPostNotFoundException) {
            return PostDeletion::AlreadyGone;
        }

        return JsonNodeModel::of($answer->json())->has('commit') ? PostDeletion::Deleted : PostDeletion::AlreadyGone;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.v\Z');
    }
}
