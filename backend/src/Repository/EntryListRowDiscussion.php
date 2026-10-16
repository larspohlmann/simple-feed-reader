<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Discussion;
use App\Enum\CommentsLoad;

final readonly class EntryListRowDiscussion
{
    public function __construct(public ?string $url, public ?CommentsLoad $commentsLoad)
    {
    }

    public static function storedOf(Discussion $discussion): self
    {
        return new self($discussion->url, $discussion->commentsLoad);
    }
}
