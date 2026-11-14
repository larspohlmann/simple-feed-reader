<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\BlueskyRecords;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\SocialLikeResultModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\BlueskyRecordUri;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPostLiker implements PostLikerInterface
{
    public function __construct(private BlueskyRecords $records)
    {
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        if (null !== $post->existingLikeId) {
            return new SocialLikeResultModel($post->existingLikeId);
        }
        $likeId = BlueskyRecordUri::likeOrNull($this->records->createAbout($post, BlueskyRecordUri::LIKE))
            ?? throw new SocialNetworkUnreachableException('Bluesky did not name the like it created.');

        return new SocialLikeResultModel($likeId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $this->records->delete($account, BlueskyRecordUri::LIKE, $likeId);
    }
}
