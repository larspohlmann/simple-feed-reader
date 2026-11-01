<?php

declare(strict_types=1);

namespace App\Service\Social\PostReposter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;

final readonly class BlueskyPostReposter implements PostReposterInterface
{
    private const string CREATE_RECORD = '/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = '/xrpc/com.atproto.repo.deleteRecord';
    private const string REPOST_COLLECTION = 'app.bsky.feed.repost';

    public function __construct(
        private BlueskySessions $sessions,
        private NaiveUtcClock $clock,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function repost(SocialPostModel $post): void
    {
        if (null !== $post->existingRepostId) {
            return;
        }
        $this->sessions->actAs($post->account, self::CREATE_RECORD, [
            'repo' => $post->account->getIdentity(),
            'collection' => self::REPOST_COLLECTION,
            'record' => [
                '$type' => self::REPOST_COLLECTION,
                'subject' => ['uri' => $post->postId, 'cid' => $post->contentId],
                'createdAt' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ]);
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        $repostUri = BlueskyRecordUri::repostOrNull($repostId)
            ?? throw new SocialPostRefusedException('The repost to undo is no Bluesky repost record.');
        try {
            $this->sessions->actAs($account, self::DELETE_RECORD, [
                'repo' => $account->getIdentity(),
                'collection' => self::REPOST_COLLECTION,
                'rkey' => BlueskyRecordUri::recordKeyOf($repostUri),
            ]);
        } catch (SocialPostNotFoundException) {
        }
    }
}
