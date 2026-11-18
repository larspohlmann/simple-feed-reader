<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentsLoad;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryDiscussion
{
    #[ORM\Column(name: 'discussion_url', length: 2048, nullable: true)]
    private ?string $url = null;

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
        $bounded = Discussion::of(
            self::bounded($discussion->url),
            self::bounded($discussion->commentsFeedUrl),
            $discussion->commentsLoad ?? CommentsLoad::Manual,
        );
        $this->url = $bounded->url;
        $this->commentsFeedUrl = $bounded->commentsFeedUrl;
        $this->commentsLoad = $bounded->commentsLoad;
    }

    public function read(): Discussion
    {
        return Discussion::of($this->url, $this->commentsFeedUrl, $this->commentsLoad ?? CommentsLoad::Manual);
    }

    public function storeCommentCount(?int $commentCount): void
    {
        $this->commentCount = $commentCount ?? $this->commentCount;
    }

    public function readCommentCount(): ?int
    {
        return $this->commentCount;
    }

    /** A read sees only part of a thread, a timeline page most of all, so the count only ever grows. */
    public function storeSelfReplyCount(int $selfReplyCount): void
    {
        $this->selfReplyCount = max($this->selfReplyCount, $selfReplyCount);
    }

    public function readSelfReplyCount(): int
    {
        return $this->selfReplyCount;
    }

    private static function bounded(?string $url): ?string
    {
        return $url === null || \strlen($url) > 2048 ? null : $url;
    }
}
