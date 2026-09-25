<?php

declare(strict_types=1);

namespace App\Http\Problem;

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
    ) {
    }
}
