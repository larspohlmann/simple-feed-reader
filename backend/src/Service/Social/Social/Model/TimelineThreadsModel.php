<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

final readonly class TimelineThreadsModel
{
    /**
     * @param list<PlacedSelfReplyModel> $placed
     * @param array<string, int>         $replyCounts by root guid, how many self-replies its chain holds
     */
    public function __construct(public array $placed, public array $replyCounts)
    {
    }

    public static function none(): self
    {
        return new self([], []);
    }
}
