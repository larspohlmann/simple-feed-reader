<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;

/** Writes and deletes the records an account keeps about a post in its own repo: likes and reposts. */
final readonly class BlueskyRecords
{
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
                'subject' => ['uri' => $post->postId, 'cid' => $post->contentId],
                'createdAt' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ]);

        return JsonNodeModel::of($answer->json())->string('uri');
    }

    /** A record Bluesky no longer has counts as deleted. */
    public function delete(SocialAccount $account, string $collection, string $recordKey): void
    {
        try {
            $this->sessions->actAs($account, self::DELETE_RECORD, [
                'repo' => $account->getIdentity(),
                'collection' => $collection,
                'rkey' => $recordKey,
            ]);
        } catch (SocialPostNotFoundException) {
        }
    }
}
