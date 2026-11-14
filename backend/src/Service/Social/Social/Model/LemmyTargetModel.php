<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

use App\Enum\VoteScore;

final readonly class LemmyTargetModel
{
    public function __construct(
        public int $postId,
        public ?int $commentId,
        public VoteScore $myVote,
        public int $score,
        public bool $isLocked,
        public ?string $authorIdentity,
        public PostPreviewModel $preview,
    ) {
    }
}
