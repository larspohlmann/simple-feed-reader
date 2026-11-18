<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Curation\StoryAi\Exception\WritingConnectionRejectedException;
use Symfony\Component\HttpFoundation\Response;

final readonly class StoryAiProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof WritingConnectionRejectedException => new ResolvedProblem(new ApiProblem(
                'writing_connection_rejected',
                'This connection cannot write story text',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
