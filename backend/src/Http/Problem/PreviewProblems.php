<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Preview\Exception\FeedPreviewException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PreviewProblems implements ExceptionProblems
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
