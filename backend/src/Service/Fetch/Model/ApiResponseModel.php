<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use Symfony\Component\HttpFoundation\Response;

final readonly class ApiResponseModel
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= Response::HTTP_OK && $this->status < Response::HTTP_MULTIPLE_CHOICES;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
