<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

final readonly class CommentsEndpointModel
{
    public function __construct(
        public string $throttleKey,
        public string $throttledMessage,
        public string $unreachableMessage,
    ) {
    }
}
