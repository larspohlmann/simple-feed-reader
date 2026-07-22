<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedCandidate
{
    public function __construct(public string $url, public ?string $title)
    {
    }
}
