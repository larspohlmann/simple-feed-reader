<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Preview\Exception\FeedPreviewException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PreviewProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof FeedPreviewException => new ResolvedProblem(new ApiProblem(
                'feed_preview_failed',
                'Feed preview failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
