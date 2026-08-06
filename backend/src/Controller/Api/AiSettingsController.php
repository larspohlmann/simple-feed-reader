<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Ai\SaveConnectionRequest;
use App\Dto\Ai\SaveModelRequest;
use App\Entity\User;
use App\Exception\AiNotConfiguredApiException;
use App\Exception\AiProviderApiException;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's AI provider. Every write verifies against the provider first,
 * so a stored configuration is one that worked — see AiProviderConfigurator.
 */
#[Route('/api/me/ai')]
final readonly class AiSettingsController
{
    public function __construct(
        private AiProviderConfigurator $configurator,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiProviderLimiter,
    ) {
    }

    #[Route('', name: 'api_me_ai_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(AiSettingsJson::state($this->configurator->settingsFor($user)));
    }

    #[Route('/connection', name: 'api_me_ai_save_connection', methods: ['PUT'])]
    public function saveConnection(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveConnectionRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $models = $this->configurator->saveConnection($user, $request->baseUrl, $request->apiKey);
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(
            AiSettingsJson::stateWithModels($this->configurator->settingsFor($user), $models),
        );
    }

    #[Route('/models', name: 'api_me_ai_models', methods: ['GET'])]
    public function models(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $models = $this->configurator->listModels($user);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(AiSettingsJson::models($models));
    }

    #[Route('/model', name: 'api_me_ai_save_model', methods: ['PUT'])]
    public function saveModel(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveModelRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $this->configurator->chooseModel($user, $request->model);
        } catch (AiNotConfiguredException $e) {
            throw new AiNotConfiguredApiException($e);
        } catch (ModelNotOfferedException | ProviderUnreachableException | CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(AiSettingsJson::state($this->configurator->settingsFor($user)));
    }

    #[Route('', name: 'api_me_ai_forget', methods: ['DELETE'])]
    public function forget(#[CurrentUser] User $user): JsonResponse
    {
        $this->configurator->forget($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
