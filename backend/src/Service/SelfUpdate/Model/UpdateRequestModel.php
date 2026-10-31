<?php

declare(strict_types=1);

namespace App\Service\SelfUpdate\Model;

final readonly class UpdateRequestModel
{
    public function __construct(
        public string $id,
        public string $requestedBy,
        public \DateTimeImmutable $requestedAt,
    ) {
    }

    public function toStateFileJson(): string
    {
        return json_encode([
            'id' => $this->id,
            'requestedBy' => $this->requestedBy,
            'requestedAt' => $this->requestedAt->format(\DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);
    }
}
