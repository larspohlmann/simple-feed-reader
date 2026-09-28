<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class SettingsProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof RelyingPartyChangeRequiresConfirmationException => new ResolvedProblem(
                new ApiProblem(
                    'relying_party_change_requires_confirmation',
                    'Relying party change requires confirmation',
                    Response::HTTP_CONFLICT,
                    $exception->getMessage(),
                ),
                extensions: ['invalidatedPasskeyCount' => $exception->invalidatedPasskeyCount],
            ),
            default => null,
        };
    }
}
