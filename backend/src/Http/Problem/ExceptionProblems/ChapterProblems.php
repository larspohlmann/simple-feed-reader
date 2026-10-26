<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use Symfony\Component\HttpFoundation\Response;

final readonly class ChapterProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof EmbedDescriptionUnreachableException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_SERVICE_UNAVAILABLE),
            ),
            default => null,
        };
    }
}
