<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
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
        $credentials = new ProviderCredentials(ProviderCredentials::normalizeBaseUrl($baseUrl), trim($apiKey));
        $models = $this->catalog->listModels($credentials);

        $sealed = $this->cipher->seal($this->identify($user), $credentials->apiKey);
        $hint = substr($credentials->apiKey, -self::HINT_LENGTH);
        $settings = $this->repository->findForUser($user);

        if (null === $settings) {
            $settings = new AiProviderSettings($user, $credentials->baseUrl, $sealed, $hint, $this->clock->now());
            $this->entityManager->persist($settings);
        } else {
            $settings->replaceConnection($credentials->baseUrl, $sealed, $hint, $this->clock->now());
        }

        $user->setAiProviderSettings($settings);
        $this->entityManager->flush();

        return $models;
    }

    /**
     * Refuses an account that has no provider row, without doing any of the
     * work that needs one. It exists so a caller holding a budget meant to cap
     * outbound calls can decline before spending it: listModels() and
     * chooseModel() raise the same refusal, but only after the caller has
     * already paid for a request that will never leave the server.
     *
     * @throws AiNotConfiguredException
     */
    public function requireConfiguration(User $user): void
    {
        $this->requireSettings($user);
    }

    /**
     * @return list<string>
     */
    public function listModels(User $user): array
    {
        return $this->catalog->listModels($this->credentialsFor($this->requireSettings($user)));
    }

    public function chooseModel(User $user, string $model): void
    {
        $settings = $this->requireSettings($user);
        $offered = $this->catalog->listModels($this->credentialsFor($settings));

        if (!\in_array($model, $offered, true)) {
            throw new ModelNotOfferedException(sprintf('That provider does not offer "%s".', $model));
        }

        $settings->chooseModel($model, $this->clock->now());
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

    private function credentialsFor(AiProviderSettings $settings): ProviderCredentials
    {
        return new ProviderCredentials(
            $settings->getBaseUrl(),
            $this->cipher->open($this->identify($settings->getUser()), $settings->getSealedApiKey()),
        );
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
