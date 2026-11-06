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
        usort($replies, self::mostInterestingFirst(...));
        $walk = new ThreadWalk($this->maxDepth, $this->maxCount);
        $walk->walkInOrder($replies);

        return new CommentsPageModel($walk->comments(), $walk->omitted());
    }

    private static function mostInterestingFirst(ReplyNode $left, ReplyNode $right): int
    {
        return [self::interestOf($right), $right->sortableTime()] <=> [self::interestOf($left), $left->sortableTime()];
    }

    private static function interestOf(ReplyNode $reply): int
    {
        $counts = $reply->counts;
        if ($counts === null) {
            return 0;
        }

        return $counts->likes + 2 * $counts->reposts + 2 * ($counts->quotes ?? 0) + 3 * $counts->replies;
    }
}
