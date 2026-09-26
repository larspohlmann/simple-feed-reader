<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Tag\Exception\TagNameTakenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class TagProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof TagNameTakenException => new ResolvedProblem(new ApiProblem(
                'tag_name_taken',
                'Tag name already in use',
                Response::HTTP_CONFLICT,
            )),
            default => null,
        };
    }
}
