<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Tag\Exception\TagNameTakenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class TagProblems implements ExceptionProblemsInterface
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
