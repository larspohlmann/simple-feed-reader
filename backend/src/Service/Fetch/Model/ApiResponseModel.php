<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

final readonly class ApiResponseModel
{
    private const int FIRST_SUCCESS = 200;
    private const int LAST_SUCCESS = 299;

    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= self::FIRST_SUCCESS && $this->status <= self::LAST_SUCCESS;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
