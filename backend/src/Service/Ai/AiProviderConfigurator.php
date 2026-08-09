<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The only writer of AiProviderSettings.
 *
 * Every write is preceded by a live call to the provider, so a stored
 * configuration is one that worked. A failed verification throws before
 * anything is persisted, which is why the existing configuration survives a
 * mistyped key.
 *
 * An account may hold several configurations; at most one is active at a
 * time, tracked as a pointer on User rather than a per-row flag. Reads
 * everywhere else in the app (recommendations, the settings page's "ready"
 * state) resolve through that one pointer.
 */
final readonly class AiProviderConfigurator
{
    private const int HINT_LENGTH = 4;
    private const int MAX_CONFIGURATIONS = 20;

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
        return $user->getActiveAiProviderSettings();
    }

    /**
     * Refuses an account with no active configuration, without doing any of
     * the work that needs one. It exists so a caller holding a budget meant to
     * cap outbound calls can decline before spending it, and it hands the row
     * back so the call that follows the spend does not have to load it again.
     *
     * @throws AiNotConfiguredException
     */
    public function requireConfiguration(User $user): AiProviderSettings
    {
        return $this->settingsFor($user)
            ?? throw new AiNotConfiguredException('This account has no active AI configuration.');
    }

    /**
     * @return list<AiProviderSettings> every configuration this account owns, oldest first
     */
    public function listConfigurations(User $user): array
    {
        return $this->repository->findAllForUser($user);
    }

    /**
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     * @throws TooManyConfigurationsException
     */
    public function addConfiguration(User $user, ?string $name, string $baseUrl, string $apiKey): AddedConfiguration
    {
        if ($this->repository->countForUser($user) >= self::MAX_CONFIGURATIONS) {
            throw new TooManyConfigurationsException(
                'This account already holds the maximum number of AI configurations.',
            );
        }

        $credentials = ProviderCredentials::fromAccountInput($baseUrl, $apiKey);
        $descriptors = $this->catalog->listModels($credentials);

        $sealed = $this->cipher->seal($this->identify($user), $credentials->apiKey);
        $hint = substr($credentials->apiKey, -self::HINT_LENGTH);

        $configuration = new AiProviderSettings(
            $user,
            $name,
            $credentials->baseUrl,
            $sealed,
            $hint,
            $this->clock->now(),
        );
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return new AddedConfiguration($configuration, $this->ids($descriptors));
    }

    /**
     * @return list<string>
     */
    public function listModels(AiProviderSettings $settings): array
    {
        return $this->ids($this->catalog->listModels($this->credentials($settings)));
    }

    /**
     * A configuration with no active sibling becomes active the moment it has
     * a usable model — otherwise the account would have to make a second,
     * separate call to start using the one connection it just finished
     * setting up.
     */
    public function chooseModel(AiProviderSettings $settings, string $model): void
    {
        $descriptor = $this->assertModelStillOffered($settings, $model);
        $settings->chooseModel($model, $this->clock->now(), $descriptor->contextWindow);
        $this->activateWhenNoneActive($settings);
        $this->entityManager->flush();
    }

    public function rename(AiProviderSettings $settings, ?string $name): void
    {
        $settings->rename($name);
        $this->entityManager->flush();
    }

    /**
     * @throws ApiKeyUnreadableException
     * @throws CredentialsRejectedException
     * @throws ModelNotOfferedException
     * @throws ModelRequiredForActivationException
     * @throws ProviderUnreachableException
     */
    public function activate(AiProviderSettings $settings): void
    {
        if (!$settings->hasModel()) {
            throw new ModelRequiredForActivationException('Choose a model before activating this configuration.');
        }

        $this->assertModelStillOffered($settings, (string) $settings->getModel());
        $settings->getUser()->setActiveAiProviderSettings($settings);
        $this->entityManager->flush();
    }

    public function deleteConfiguration(AiProviderSettings $settings): void
    {
        $user = $settings->getUser();

        if ($settings === $user->getActiveAiProviderSettings()) {
            $user->setActiveAiProviderSettings(null);
        }

        $this->entityManager->remove($settings);
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

    private function activateWhenNoneActive(AiProviderSettings $settings): void
    {
        $user = $settings->getUser();

        if (null === $user->getActiveAiProviderSettings()) {
            $user->setActiveAiProviderSettings($settings);
        }
    }

    /**
     * The shared verify step behind both chooseModel() and activate(): a
     * chosen model is only as good as the provider still offering it, so
     * activating a configuration re-checks it exactly like choosing does.
     * Returns the descriptor rather than stashing it on a field, so the two
     * callers stay free of shared mutable state between the call and its use.
     *
     * @throws ApiKeyUnreadableException
     * @throws CredentialsRejectedException
     * @throws ModelNotOfferedException
     * @throws ProviderUnreachableException
     */
    private function assertModelStillOffered(AiProviderSettings $settings, string $model): ModelDescriptor
    {
        $offered = $this->catalog->listModels($this->credentials($settings));

        return $this->offeredDescriptor($offered, $model);
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

    /**
     * The account id is bound into the sealed key, so an unsaved User cannot be
     * sealed for: the id it would get on flush is not the one used here.
     */
    private function identify(User $user): int
    {
        return $user->getId() ?? throw new \LogicException('Cannot seal a key for an unsaved account.');
    }
}
