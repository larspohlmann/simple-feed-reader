<?php

declare(strict_types=1);

namespace App\Service\Social\PostReposter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyRecordUri;

final readonly class BlueskyPostReposter implements PostReposterInterface
{
    private const string REPOST_COLLECTION = 'app.bsky.feed.repost';

    public function __construct(private BlueskyRecords $records)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function repost(SocialPostModel $post): void
    {
        $this->records->createAbout($post, self::REPOST_COLLECTION);
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::repostOrNull($repostId) ?? '')
            ?? throw new SocialPostRefusedException('The repost to undo is no Bluesky repost record.');
        $this->records->delete($account, self::REPOST_COLLECTION, $recordKey);
    }
}
