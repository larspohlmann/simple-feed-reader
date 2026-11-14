<?php

declare(strict_types=1);

namespace App\Service\Url\Model;

final readonly class LemmyPostPageModel
{
    public function __construct(public string $host, public int $postId)
    {
    }
}
