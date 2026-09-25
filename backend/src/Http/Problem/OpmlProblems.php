<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Opml\Exception\InvalidOpmlException;
use Symfony\Component\HttpFoundation\Response;

final readonly class OpmlProblems implements ExceptionProblems
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
