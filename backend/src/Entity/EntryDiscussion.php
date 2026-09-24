<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentsLoad;
use App\Service\Discussion\Discussion;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class EntryDiscussion
{
    #[ORM\Column(name: 'discussion_url', length: 2048, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'comments_feed_url', length: 2048, nullable: true)]
    private ?string $commentsFeedUrl = null;

    #[ORM\Column(name: 'comments_load', length: 8, nullable: true, enumType: CommentsLoad::class)]
    private ?CommentsLoad $commentsLoad = null;

    public function store(Discussion $discussion): void
    {
        $this->url = self::bounded($discussion->url);
        $this->commentsFeedUrl = self::bounded($discussion->commentsFeedUrl);
        $this->commentsLoad = $this->commentsFeedUrl === null ? null : $discussion->commentsLoad;
    }

    public function read(): Discussion
    {
        if ($this->commentsFeedUrl !== null && $this->commentsLoad !== null) {
            return Discussion::withCommentsFeed($this->url, $this->commentsFeedUrl, $this->commentsLoad);
        }

        return $this->url === null ? Discussion::none() : Discussion::page($this->url);
    }

    private static function bounded(?string $url): ?string
    {
        return $url === null || \strlen($url) > 2048 ? null : $url;
    }
}
