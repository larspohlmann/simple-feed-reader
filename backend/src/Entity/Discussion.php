<?php

declare(strict_types=1);

namespace App\Entity;

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

    public static function withCommentsFeed(?string $pageUrl, string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $load);
    }

    public static function of(?string $pageUrl, ?string $commentsFeedUrl, CommentsLoad $load): self
    {
        $keepsLoad = $commentsFeedUrl !== null || ($pageUrl !== null && $load !== CommentsLoad::Manual);

        return new self($pageUrl, $commentsFeedUrl, $keepsLoad ? $load : null);
    }

    public static function platformThread(string $pageUrl): self
    {
        return new self($pageUrl, null, CommentsLoad::Auto);
    }
}
