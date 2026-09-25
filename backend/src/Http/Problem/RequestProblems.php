<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\InvalidSelectionException;
use App\Exception\ValidationException;
use App\Repository\Exception\RecordNotFoundException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequestProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof ValidationException => new ResolvedProblem(new ApiProblem(
                'validation_error',
                'Validation failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'One or more fields are invalid.',
                $exception->errors,
            )),
            $exception instanceof RecordNotFoundException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_NOT_FOUND, $exception->getMessage()),
            ),
            $exception instanceof InvalidSelectionException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage()),
            ),
            default => null,
        };
    }
}
