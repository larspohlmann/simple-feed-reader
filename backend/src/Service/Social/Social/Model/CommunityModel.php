<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Model;

final readonly class CommunityModel
{
    public function __construct(public int $id, public string $handle)
    {
    }
}
