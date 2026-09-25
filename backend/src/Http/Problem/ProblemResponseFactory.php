<?php

declare(strict_types=1);

namespace App\Http\Problem;

use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class ProblemResponseFactory
{
    public function create(ResolvedProblem $resolved): JsonResponse
    {
        // array_merge, not +: the union keeps the LEFT value, so a pass-through Content-Type would win.
        return new JsonResponse(
            array_merge($resolved->problem->toArray(), $resolved->extensions),
            $resolved->problem->status,
            array_merge($resolved->headers, ['Content-Type' => 'application/problem+json']),
        );
    }
}
