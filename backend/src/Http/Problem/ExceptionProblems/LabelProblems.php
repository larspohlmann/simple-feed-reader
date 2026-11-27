<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Library\Label\Exception\LabelNameTakenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class LabelProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof LabelNameTakenException => new ResolvedProblem(new ApiProblem(
                'label_name_taken',
                'Label name already in use',
                Response::HTTP_CONFLICT,
            )),
            default => null,
        };
    }
}
