<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

final readonly class ContextReferenceModel
{
    public function __construct(
        public ?string $answeredId = null,
        public ?string $threadRootId = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->answeredId === null && $this->threadRootId === null;
    }
}
