<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class CommentsAnswerModel
{
    private function __construct(public string $body, public bool $isStillGathering)
    {
    }

    public static function settled(string $body): self
    {
        return new self($body, false);
    }

    public static function stillGathering(string $body): self
    {
        return new self($body, true);
    }
}
