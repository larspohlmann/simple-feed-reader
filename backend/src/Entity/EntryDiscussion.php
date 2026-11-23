<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentsLoad;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryDiscussion implements DiscussionCountStoreInterface
{
    #[ORM\Column(name: 'discussion_url', length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'discussion_home_url', length: 2048, nullable: true)]
    private ?string $homeUrl = null;

    #[ORM\Column(name: 'comments_feed_url', length: 2048, nullable: true)]
    private ?string $commentsFeedUrl = null;

    #[ORM\Column(name: 'comments_load', length: 8, nullable: true, enumType: CommentsLoad::class)]
    private ?CommentsLoad $commentsLoad = null;

    #[ORM\Column(name: 'comment_count', nullable: true)]
    private ?int $commentCount = null;

    #[ORM\Column(name: 'self_reply_count', type: Types::SMALLINT, options: ['default' => 0])]
    private int $selfReplyCount = 0;

    public function store(Discussion $discussion): void
    {
        $bounded = Discussion::fromPageAndFeed(
            self::bounded($discussion->url),
            self::bounded($discussion->commentsFeedUrl),
            $discussion->commentsLoad ?? CommentsLoad::Manual,
        );
        $this->url = $bounded->url;
        $this->commentsFeedUrl = $bounded->commentsFeedUrl;
        $this->commentsLoad = $bounded->commentsLoad;
        $this->homeUrl = self::bounded($discussion->homeUrl) ?? $this->homeUrl;
    }

    public function read(): Discussion
    {
        return Discussion::fromPageAndFeed(
            $this->url,
            $this->commentsFeedUrl,
            $this->commentsLoad ?? CommentsLoad::Manual,
        )->withHomeUrl($this->homeUrl);
    }

    public function recordCounts(DiscussionCounts $counts): void
    {
        $this->commentCount = $counts->commentCount ?? $this->commentCount;
    }

    public function getCommentCount(): ?int
    {
        return $this->commentCount;
    }

    public function recordSelfReplyCount(int $selfReplyCount): void
    {
        $this->selfReplyCount = max($this->selfReplyCount, $selfReplyCount);
    }

    public function getSelfReplyCount(): int
    {
        return $this->selfReplyCount;
    }

    public function clearCounts(): void
    {
        $this->commentCount = null;
        $this->selfReplyCount = 0;
    }

    private static function bounded(?string $url): ?string
    {
        return $url === null || \strlen($url) > 2048 ? null : $url;
    }
}
