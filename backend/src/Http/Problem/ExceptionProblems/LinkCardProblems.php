<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\LinkCard\Exception\LinkCardTokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

final readonly class LinkCardProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof LinkCardTokenMismatchException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_NOT_FOUND),
            ),
            default => null,
        };
    }
}
