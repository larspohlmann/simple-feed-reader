<?php

declare(strict_types=1);

namespace App\Service\Discussion;

use App\Enum\CommentsLoad;

final readonly class Discussion
{
    private function __construct(
        public ?string $url,
        public ?string $commentsFeedUrl,
        public ?CommentsLoad $commentsLoad,
        public bool $bodyIsOpeningPost = false,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function withCommentsFeed(?string $pageUrl, string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $load);
    }

    public static function of(?string $pageUrl, ?string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $commentsFeedUrl === null ? null : $load);
    }

    public function withOpeningPostBody(): self
    {
        return new self($this->url, $this->commentsFeedUrl, $this->commentsLoad, true);
    }
}
