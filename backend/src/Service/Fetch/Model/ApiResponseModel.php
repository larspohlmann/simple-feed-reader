<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use Symfony\Component\HttpFoundation\Response;

final readonly class ApiResponseModel
{
    /** @param array<array<string>> $headers keyed by lower-cased name, as Symfony's HttpClient returns them */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= Response::HTTP_OK && $this->status < Response::HTTP_MULTIPLE_CHOICES;
    }

    public function isServerError(): bool
    {
        return $this->status >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
