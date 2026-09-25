<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Symfony\Component\HttpFoundation\Response;

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

    public static function forStatus(int $status, ?string $detail = null): self
    {
        return new self(
            match ($status) {
                Response::HTTP_UNAUTHORIZED => 'unauthorized',
                Response::HTTP_FORBIDDEN => 'forbidden',
                Response::HTTP_NOT_FOUND => 'not_found',
                Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
                Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
                default => $status >= 500 ? 'internal_error' : 'request_error',
            },
            Response::$statusTexts[$status] ?? 'Error',
            $status,
            $detail,
        );
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
