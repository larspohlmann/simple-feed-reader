<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use Symfony\Component\HttpFoundation\Response;

final readonly class AiProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof AiNotConfiguredException => new ResolvedProblem(new ApiProblem(
                'ai_not_configured',
                'No AI provider is configured',
                Response::HTTP_NOT_FOUND,
                'Save an endpoint and an API key first.',
            )),
            $exception instanceof ConfigurationNotFoundException => new ResolvedProblem(new ApiProblem(
                'ai_configuration_not_found',
                'AI configuration not found',
                Response::HTTP_NOT_FOUND,
                'No such AI configuration for this account.',
            )),
            $exception instanceof TooManyConfigurationsException => new ResolvedProblem(new ApiProblem(
                'ai_configuration_limit',
                'Too many AI configurations',
                Response::HTTP_CONFLICT,
                'This account already holds the maximum number of AI configurations.',
            )),
            $exception instanceof AiKeyUnreadableException => new ResolvedProblem(new ApiProblem(
                'ai_key_unreadable',
                'The stored API key could not be read',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'The stored API key can no longer be read. Enter it again.',
            )),
            self::isProviderRefusal($exception) => new ResolvedProblem(new ApiProblem(
                'ai_provider_rejected',
                'The AI provider could not be used',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }

    private static function isProviderRefusal(\Throwable $exception): bool
    {
        return $exception instanceof ProviderUnreachableException
            || $exception instanceof CredentialsRejectedException
            || $exception instanceof ModelNotOfferedException
            || $exception instanceof ModelRequiredForActivationException;
    }
}
