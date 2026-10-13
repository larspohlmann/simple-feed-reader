<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;

final readonly class BlueskyPostLiker implements PostLikerInterface
{
    private const string CREATE_RECORD = '/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = '/xrpc/com.atproto.repo.deleteRecord';
    private const string LIKE_COLLECTION = 'app.bsky.feed.like';

    public function __construct(
        private BlueskySessions $sessions,
        private NaiveUtcClock $clock,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        if (null !== $post->existingLikeId) {
            return new SocialLikeResultModel($post->existingLikeId);
        }
        $answer = $this->sessions->actAs($post->account, self::CREATE_RECORD, [
            'repo' => $post->account->getIdentity(),
            'collection' => self::LIKE_COLLECTION,
            'record' => [
                '$type' => self::LIKE_COLLECTION,
                'subject' => ['uri' => $post->postId, 'cid' => $post->contentId],
                'createdAt' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ]);

        $createdUri = JsonNodeModel::of($answer->json())->string('uri');
        $likeId = BlueskyRecordUri::likeOrNull($createdUri)
            ?? throw new SocialNetworkUnreachableException('Bluesky did not name the like it created.');

        return new SocialLikeResultModel($likeId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $recordKey = BlueskyRecordUri::recordKeyOf($likeId)
            ?? throw new SocialPostRefusedException('The like to take back is no Bluesky like record.');
        try {
            $this->sessions->actAs($account, self::DELETE_RECORD, [
                'repo' => $account->getIdentity(),
                'collection' => self::LIKE_COLLECTION,
                'rkey' => $recordKey,
            ]);
        } catch (SocialPostNotFoundException) {
            return;
        }
    }
}
