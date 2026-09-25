<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Exception\ValidationException;
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
            default => null,
        };
    }
}
