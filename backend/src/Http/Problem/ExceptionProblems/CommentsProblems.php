<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Comments\Exception\NoCommentsFeedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class CommentsProblems implements ExceptionProblemsInterface
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
