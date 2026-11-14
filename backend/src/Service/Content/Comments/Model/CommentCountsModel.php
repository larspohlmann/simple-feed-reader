<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

/** A reply's public counts as its network reports them; Mastodon has no quotes. */
final readonly class CommentCountsModel
{
    public function __construct(
        public int $likes,
        public int $reposts,
        public int $replies,
        public ?int $quotes,
    ) {
    }
}
