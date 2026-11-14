<?php

declare(strict_types=1);

namespace App\Dto\Discussion;

use App\Enum\VoteScore;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class VoteRequest
{
    public function __construct(
        #[Assert\Choice(choices: [1, 0, -1])]
        public int $score,
    ) {
    }

    public function toScore(): VoteScore
    {
        return VoteScore::from($this->score);
    }
}
