<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Symfony\Component\HttpFoundation\Response;

/** An exception's problem document plus the headers and extension members that travel with it. */
final readonly class ResolvedProblem
{
    /**
     * @param array<array-key, mixed> $headers    HttpExceptionInterface::getHeaders() is an untyped array
     * @param array<string, mixed>    $extensions RFC 7807 extension members
     */
    public function __construct(
        public ApiProblem $problem,
        public array $headers = [],
        public array $extensions = [],
        public bool $expected = false,
    ) {
    }

    /**
     * A 5xx the deployment answers by design (no updater installed, a throttled network), so nothing to log.
     *
     * @param array<array-key, mixed> $headers
     */
    public static function expected(ApiProblem $problem, array $headers = []): self
    {
        return new self($problem, $headers, expected: true);
    }

    public function isServerFault(): bool
    {
        return !$this->expected && $this->problem->status >= Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}
