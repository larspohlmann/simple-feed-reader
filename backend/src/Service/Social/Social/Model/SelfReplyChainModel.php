<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Service\Content\Comments\Model\EntryCommentModel;

/** The author's replies continuing a post: the first ones to fold, and how many the chain holds in all. */
final readonly class SelfReplyChainModel
{
    /** @param list<EntryCommentModel> $replies */
    public function __construct(public array $replies, public int $replyCount)
    {
    }

    public static function none(): self
    {
        return new self([], 0);
    }
}
