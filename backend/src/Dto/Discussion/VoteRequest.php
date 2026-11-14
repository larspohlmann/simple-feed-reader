<?php

declare(strict_types=1);

namespace App\Dto\Discussion;

use App\Enum\VoteScore;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class VoteRequest
{
    public function __construct(
        #[Assert\Choice(callback: 'scores')]
        public int $score,
    ) {
    }

    /** @return array<int, int> */
    public static function scores(): array
    {
        return array_map(static fn (VoteScore $score): int => $score->value, VoteScore::cases());
    }

    public function toScore(): VoteScore
    {
        return VoteScore::from($this->score);
    }
}
