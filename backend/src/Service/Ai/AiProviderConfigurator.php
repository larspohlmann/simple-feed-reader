<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The only writer of AiProviderSettings.
 *
 * Every write is preceded by a live call to the provider, so a stored
 * configuration is one that worked. A failed verification throws before
 * anything is persisted, which is why the existing configuration survives a
 * mistyped key.
 */
final readonly class AiProviderConfigurator
{
    private const int HINT_LENGTH = 4;

    public function __construct(
        private ModelCatalog $catalog,
        private ApiKeyCipher $cipher,
        private AiProviderSettingsRepository $repository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function settingsFor(User $user): ?AiProviderSettings
    {
        return $this->repository->findForUser($user);
    }

    /**
     * @return list<string> the models the provider offers
     */
    public function saveConnection(User $user, string $baseUrl, string $apiKey): array
    {
        $credentials = ProviderCredentials::fromAccountInput($baseUrl, $apiKey);
        $descriptors = $this->catalog->listModels($credentials);

        $sealed = $this->cipher->seal($this->identify($user), $credentials->apiKey);
        $hint = substr($credentials->apiKey, -self::HINT_LENGTH);
        $settings = $this->repository->findForUser($user);

        if (null === $settings) {
            $settings = new AiProviderSettings($user, null, $credentials->baseUrl, $sealed, $hint, $this->clock->now());
            $this->entityManager->persist($settings);
        } else {
            $settings->replaceConnection($credentials->baseUrl, $sealed, $hint, $this->clock->now());
        }

        $user->setAiProviderSettings($settings);
        $this->entityManager->flush();

        return $this->ids($descriptors);
    }

    /**
     * Refuses an account that has no provider row, without doing any of the
     * work that needs one. It exists so a caller holding a budget meant to cap
     * outbound calls can decline before spending it, and it hands the row back
     * so the call that follows the spend does not have to load it again.
     *
     * @throws AiNotConfiguredException
     */
    public function requireConfiguration(User $user): AiProviderSettings
    {
        return $this->requireSettings($user);
    }

    /**
     * @return list<string>
     */
    public function listModels(AiProviderSettings $settings): array
    {
        return $this->ids($this->catalog->listModels($this->credentials($settings)));
    }

    public function chooseModel(AiProviderSettings $settings, string $model): void
    {
        $offered = $this->catalog->listModels($this->credentials($settings));
        $descriptor = $this->offeredDescriptor($offered, $model);

        $settings->chooseModel($model, $this->clock->now(), $descriptor->contextWindow);
        $this->entityManager->flush();
    }

    public function forget(User $user): void
    {
        $settings = $this->repository->findForUser($user);

        if (null === $settings) {
            return;
        }

        $this->entityManager->remove($settings);
        $user->setAiProviderSettings(null);
        $this->entityManager->flush();
    }

    /**
     * Public so a service that must call the provider directly — a prompt
     * runner, say — can reuse the one place that opens the sealed key, rather
     * than duplicating the cipher call.
     *
     * @throws ApiKeyUnreadableException
     */
    public function credentials(AiProviderSettings $settings): ProviderCredentials
    {
        return ProviderCredentials::fromStoredConfiguration(
            $settings->getBaseUrl(),
            $this->cipher->open($this->identify($settings->getUser()), $settings->getSealedApiKey()),
        );
    }

    /**
     * @param list<ModelDescriptor> $offered
     */
    private function offeredDescriptor(array $offered, string $model): ModelDescriptor
    {
        foreach ($offered as $descriptor) {
            if ($descriptor->id === $model) {
                return $descriptor;
            }
        }

        throw new ModelNotOfferedException(sprintf('That provider does not offer "%s".', $model));
    }

    /**
     * @param list<ModelDescriptor> $descriptors
     *
     * @return list<string>
     */
    private function ids(array $descriptors): array
    {
        return array_map(static fn (ModelDescriptor $descriptor): string => $descriptor->id, $descriptors);
    }

    private function requireSettings(User $user): AiProviderSettings
    {
        return $this->repository->findForUser($user)
            ?? throw new AiNotConfiguredException('This account has no AI provider configured.');
    }

    /**
     * The account id is bound into the sealed key, so an unsaved User cannot be
     * sealed for: the id it would get on flush is not the one used here.
     */
    private function identify(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot seal a key for an unsaved account.');
    }
}
