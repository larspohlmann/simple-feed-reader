<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The full outcome of mapping one exception to an error response: the RFC 7807
 * document, the HTTP headers that travel with it, and any payload extension
 * members added alongside the standard problem fields. Grouping the three keeps
 * ApiExceptionListener's per-exception branches to a single return value each.
 */
final readonly class ResolvedProblem
{
    /**
     * `$headers` keys are HTTP field names, but the type stays unconstrained
     * because it also carries HttpExceptionInterface::getHeaders(), which
     * Symfony declares as a bare `array`.
     *
     * @param array<array-key, mixed> $headers    pass-through/response headers
     * @param array<string, mixed>    $extensions RFC 7807 extension members
     */
    public function __construct(
        public ApiProblem $problem,
        public array $headers = [],
        public array $extensions = [],
    ) {
    }
}
