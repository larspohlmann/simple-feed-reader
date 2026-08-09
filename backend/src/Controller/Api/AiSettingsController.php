<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Ai\AddConfigurationRequest;
use App\Dto\Ai\RenameConfigurationRequest;
use App\Dto\Ai\SaveModelRequest;
use App\Entity\User;
use App\Exception\AiConfigurationNotFoundApiException;
use App\Exception\AiKeyUnreadableApiException;
use App\Exception\AiProviderApiException;
use App\Exception\TooManyAiConfigurationsApiException;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\ConfigurationNotFoundException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The account's AI provider configurations. Every write that talks to the
 * provider verifies against it first, so a stored configuration is one that
 * worked — see AiProviderConfigurator. Every route that takes an `{id}` is
 * ownership-scoped through AiConfigurationForUser: another account's id
 * answers 404, not 403, so a caller cannot learn that the id exists at all.
 */
#[Route('/api/me/ai')]
final readonly class AiSettingsController
{
    public function __construct(
        private AiProviderConfigurator $configurator,
        private AiConfigurationForUser $configuration,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiProviderLimiter,
    ) {
    }

    #[Route('', name: 'api_me_ai_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(AiSettingsJson::list(
            $this->configurator->listConfigurations($user),
            $this->configurator->settingsFor($user)?->getId(),
        ));
    }

    #[Route('/configs', name: 'api_me_ai_add', methods: ['POST'])]
    public function add(
        #[CurrentUser] User $user,
        #[MapRequestPayload] AddConfigurationRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        try {
            $added = $this->configurator->addConfiguration($user, $request->name, $request->baseUrl, $request->apiKey);
        } catch (TooManyConfigurationsException $e) {
            throw new TooManyAiConfigurationsApiException($e);
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(
            AiSettingsJson::added($added->configuration, $added->modelIds),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/configs/{id}/models', name: 'api_me_ai_models', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function models(#[CurrentUser] User $user, int $id): JsonResponse
    {
        try {
            $configuration = $this->configuration->require($user, $id);
            $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
            $models = $this->configurator->listModels($configuration);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        } catch (ApiKeyUnreadableException $e) {
            throw new AiKeyUnreadableApiException($e);
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(['models' => $models]);
    }

    #[Route('/configs/{id}/model', name: 'api_me_ai_save_model', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function saveModel(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SaveModelRequest $request,
    ): JsonResponse {
        try {
            $configuration = $this->configuration->require($user, $id);
            $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
            $this->configurator->chooseModel($configuration, $request->model);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        } catch (ApiKeyUnreadableException $e) {
            throw new AiKeyUnreadableApiException($e);
        } catch (ModelNotOfferedException | ProviderUnreachableException | CredentialsRejectedException $e) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route('/configs/{id}/name', name: 'api_me_ai_rename', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function rename(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] RenameConfigurationRequest $request,
    ): JsonResponse {
        try {
            $configuration = $this->configuration->require($user, $id);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        }

        $this->configurator->rename($configuration, $request->name);

        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
    }

    #[Route('/configs/{id}/active', name: 'api_me_ai_activate', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function activate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        try {
            $configuration = $this->configuration->require($user, $id);
            $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
            $this->configurator->activate($configuration);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        } catch (ApiKeyUnreadableException $e) {
            throw new AiKeyUnreadableApiException($e);
        } catch (
            ModelRequiredForActivationException
            | ModelNotOfferedException
            | ProviderUnreachableException
            | CredentialsRejectedException $e
        ) {
            throw new AiProviderApiException($e->getMessage(), $e);
        }

        return new JsonResponse(AiSettingsJson::configuration($configuration, $configuration->getId()));
    }

    #[Route('/configs/{id}', name: 'api_me_ai_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, int $id): JsonResponse
    {
        try {
            $configuration = $this->configuration->require($user, $id);
        } catch (ConfigurationNotFoundException $e) {
            throw new AiConfigurationNotFoundApiException($e);
        }

        $this->configurator->deleteConfiguration($configuration);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
