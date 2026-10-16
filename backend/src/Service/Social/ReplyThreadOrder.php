<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Social\Pass\ReplyNode;
use App\Service\Social\Pass\ThreadWalk;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReplyThreadOrder
{
    public function __construct(
        #[Autowire('%social_replies_max_depth%')] private int $maxDepth,
        #[Autowire('%social_replies_max_count%')] private int $maxCount,
    ) {
    }

    /** @param list<ReplyNode> $replies the post's direct replies */
    public function pageOf(array $replies): CommentsPageModel
    {
        $walk = new ThreadWalk($this->maxDepth, $this->maxCount);
        $walk->walkNewestFirst($replies);

        return new CommentsPageModel($walk->comments(), $walk->omitted());
    }
}
