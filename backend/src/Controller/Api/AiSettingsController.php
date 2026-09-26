<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Ai\AddConfigurationRequest;
use App\Dto\Ai\RenameConfigurationRequest;
use App\Dto\Ai\SaveModelRequest;
use App\Dto\Ai\SetBatchConcurrencyRequest;
use App\Dto\Ai\SetMaxBatchSizeRequest;
use App\Dto\Ai\SetReasoningRequest;
use App\Dto\Ai\SetSlowModelRequest;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Service\Ai\AiConfigurationEditor;
use App\Service\Ai\AiConfigurationForUser;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

/**
 * The account's AI provider configurations. A write that talks to the provider verifies against it first (see
 * AiProviderConfigurator). Every `{id}` route resolves through AiConfigurationForUser, so another account's id
 * answers 404, not 403, and a caller cannot learn that it exists.
 */
#[Route('/api/me/ai')]
final readonly class AiSettingsController
{
    public function __construct(
        private AiProviderConfigurator $configurator,
        private AiConfigurationEditor $editor,
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
            $user->getActiveAiProviderSettings()?->getId(),
        ));
    }

    #[Route('/configs', name: 'api_me_ai_add', methods: ['POST'])]
    public function add(
        #[CurrentUser] User $user,
        #[MapRequestPayload] AddConfigurationRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $added = $this->configurator->addConfiguration($user, $request->name, $request->baseUrl, $request->apiKey);

        return new JsonResponse(
            AiSettingsJson::added($added->configuration, $added->modelIds),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/configs/{id}/duplicate', name: 'api_me_ai_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $source = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $copy = $this->configurator->duplicateConfiguration($source);

        return new JsonResponse(
            AiSettingsJson::configurationFor($copy, $user),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/configs/{id}/models', name: 'api_me_ai_models', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function models(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);

        return new JsonResponse(AiSettingsJson::models($this->configurator->listModels($configuration)));
    }

    #[Route('/configs/{id}/model', name: 'api_me_ai_save_model', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function saveModel(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SaveModelRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $this->configurator->chooseModel($configuration, $request->model);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route('/configs/{id}/name', name: 'api_me_ai_rename', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function rename(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] RenameConfigurationRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->rename($configuration, $request->name);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route(
        '/configs/{id}/reasoning',
        name: 'api_me_ai_set_reasoning',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setReasoning(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetReasoningRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setSuppressReasoning($configuration, $request->suppressReasoning);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route(
        '/configs/{id}/slow-model',
        name: 'api_me_ai_set_slow_model',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setSlowModel(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetSlowModelRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setSlowModel($configuration, $request->slowModel);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route(
        '/configs/{id}/batch-concurrency',
        name: 'api_me_ai_set_batch_concurrency',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    public function setBatchConcurrency(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SetBatchConcurrencyRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setBatchConcurrency($configuration, $request->batchConcurrency);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route(
        '/configs/{id}/max-batch-size',
        name: 'api_me_ai_set_max_batch_size',
        requirements: ['id' => '\d+'],
        methods: ['PUT'],
    )]
    /**
     * REQUIRE_ALL_PROPERTIES: the one nullable payload here, so a body that never mentions `maxBatchSize` must
     * not clear the account's cap (#445). Clearing takes an explicit `{"maxBatchSize": null}`.
     */
    public function setMaxBatchSize(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload(serializationContext: [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true])]
        SetMaxBatchSizeRequest $request,
    ): JsonResponse {
        $configuration = $this->configuration->require($user, $id);
        $this->editor->setMaxBatchSize($configuration, $request->maxBatchSize);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route('/configs/{id}/active', name: 'api_me_ai_activate', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function activate(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $configuration = $this->configuration->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->aiProviderLimiter, $user);
        $this->configurator->activate($configuration);

        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
    }

    #[Route('/configs/{id}', name: 'api_me_ai_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->configurator->deleteConfiguration($this->configuration->require($user, $id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
