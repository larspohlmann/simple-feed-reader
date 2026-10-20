<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Reader\Exception\ReaderUrlTokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

final readonly class ReaderProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof ReaderUrlTokenMismatchException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_NOT_FOUND),
            ),
            default => null,
        };
    }
}
