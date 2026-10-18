<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class TimelineThreadsModel
{
    /** @param list<PlacedSelfReplyModel> $placed */
    public function __construct(public array $placed)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }
}
