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
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function page(string $url): self
    {
        return new self($url, null, null);
    }

    public static function withCommentsFeed(?string $pageUrl, string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $load);
    }

    public function hasCommentsFeed(): bool
    {
        return $this->commentsFeedUrl !== null;
    }
}
