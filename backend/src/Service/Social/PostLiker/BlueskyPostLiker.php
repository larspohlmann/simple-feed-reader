<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;

final readonly class BlueskyPostLiker implements PostLikerInterface
{
    private const string LIKE_COLLECTION = 'app.bsky.feed.like';

    public function __construct(private BlueskyRecords $records)
    {
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
        $likeId = BlueskyRecordUri::likeOrNull($this->records->createAbout($post, self::LIKE_COLLECTION))
            ?? throw new SocialNetworkUnreachableException('Bluesky did not name the like it created.');

        return new SocialLikeResultModel($likeId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::likeOrNull($likeId) ?? '')
            ?? throw new SocialPostRefusedException('The like to take back is no Bluesky like record.');
        $this->records->delete($account, self::LIKE_COLLECTION, $recordKey);
    }
}
