<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Passkey\Exception\UnknownChallengeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasskeyChallengeProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UnknownChallengeException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_challenge',
                'Unknown or expired passkey challenge',
                Response::HTTP_BAD_REQUEST,
            )),
            default => null,
        };
    }
}
