<?php

declare(strict_types=1);

namespace App\Http;

/**
 * RFC 7807 problem document. `type` is a stable machine-readable slug the
 * Angular client switches on — never a URL, never localised, never renamed
 * without a frontend change.
 */
final readonly class ApiProblem
{
    /**
     * @param array<string, list<string>> $errors field name => messages
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public ?string $detail = null,
        public array $errors = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];

        if (null !== $this->detail) {
            $payload['detail'] = $this->detail;
        }

        if ([] !== $this->errors) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }
}
