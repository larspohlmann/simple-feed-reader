<?php

declare(strict_types=1);

namespace App\Service\Social\PostReposter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPostReposter implements PostReposterInterface
{
    public function __construct(private BlueskyRecords $records)
    {
    }

    public function repost(SocialPostModel $post): void
    {
        $this->records->createAbout($post, BlueskyRecordUri::REPOST);
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        $this->records->delete($account, BlueskyRecordUri::REPOST, $repostId);
    }
}
