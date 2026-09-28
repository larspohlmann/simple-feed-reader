<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\RateLimit\Exception\RateLimitedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RateLimitProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof RateLimitedException => new ResolvedProblem(
                new ApiProblem(
                    'rate_limited',
                    'Too many requests',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    'Too many attempts. Try again later.',
                ),
                ['Retry-After' => (string) $exception->retryAfterSeconds],
            ),
            default => null,
        };
    }
}
