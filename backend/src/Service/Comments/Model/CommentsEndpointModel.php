<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class CommentsEndpointModel
{
    public function __construct(
        public string $throttleKey,
        public string $url,
        public string $throttledMessage,
        public string $unreachableMessage,
    ) {
    }
}
