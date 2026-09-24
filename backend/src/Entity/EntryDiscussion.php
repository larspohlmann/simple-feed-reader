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

    private static function bounded(?string $url): ?string
    {
        return $url === null || \strlen($url) > 2048 ? null : $url;
    }
}
