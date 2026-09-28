<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Opml\Exception\InvalidOpmlException;
use Symfony\Component\HttpFoundation\Response;

final readonly class OpmlProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof InvalidOpmlException => new ResolvedProblem(new ApiProblem(
                'invalid_opml',
                'The OPML document could not be parsed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
