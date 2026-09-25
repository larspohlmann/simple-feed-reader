<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Comments\Exception\NoCommentsFeedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CommentsProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof NoCommentsFeedException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_NOT_FOUND),
            ),
            default => null,
        };
    }
}
