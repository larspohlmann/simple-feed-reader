<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\AiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ModelRequiredForActivationException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\TooManyConfigurationsException;
use App\Service\Ai\Factory\AiConfigurationFactory;
use App\Service\Ai\Model\AddedConfigurationModel;
use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;
use App\Service\Crypto\Exception\SecretUnreadableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The writer of AiProviderSettings that owns the provider relationship —
 * creating, verifying, and removing a connection. Local edits to a saved row
 * (its name and preferences) live in AiConfigurationEditor instead.
 *
 * A write that reaches the provider is preceded by a live call to it, so a
 * stored configuration is one that worked. A failed verification throws before
 * anything is persisted, which is why the existing configuration survives a
 * mistyped key. The one write that does not call the provider is
 * duplicateConfiguration(): it reuses an already-verified row's credentials.
 *
 * An account may hold several configurations; at most one is active at a
 * time, tracked as a pointer on User rather than a per-row flag. Reads
 * everywhere else in the app (recommendations, the settings page's "ready"
 * state) resolve through that one pointer.
 */
final readonly class AiProviderConfigurator
{
    private const int MAX_CONFIGURATIONS = 20;

    public function __construct(
        private ModelCatalogInterface $catalog,
        private ApiKeyCipher $cipher,
        private AiProviderSettingsRepository $aiProviderSettings,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private AiConfigurationFactory $configurationFactory,
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
        return $this->aiProviderSettings->findAllForUser($user);
    }

    /**
     * @throws CredentialsRejectedException
     * @throws ProviderUnreachableException
     * @throws TooManyConfigurationsException
     */
    public function addConfiguration(
        User $user,
        ?string $name,
        string $baseUrl,
        string $apiKey,
    ): AddedConfigurationModel {
        if ($this->aiProviderSettings->countForUser($user) >= self::MAX_CONFIGURATIONS) {
            throw new TooManyConfigurationsException(
                'This account already holds the maximum number of AI configurations.',
            );
        }

        $credentials = ProviderCredentialsModel::fromAccountInput($baseUrl, $apiKey);
        $descriptors = $this->catalog->listModels($credentials);

        $configuration = $this->configurationFactory->create($user, $name, $credentials);
        $this->entityManager->persist($configuration);
        $this->entityManager->flush();

        return new AddedConfigurationModel($configuration, $this->ids($descriptors));
    }

    /**
     * A second model on a provider the account already configured needs the
     * same endpoint and key, so this reuses both rather than making the account
     * re-enter a key it can no longer read. The source is already a verified
     * row, so no live call is made: the copy carries the source's verifiedAt.
     * The model is deliberately left unset — choosing a different one is the
     * whole point — and the copy is not activated.
     *
     * @throws AiKeyUnreadableException       the source key cannot be opened
     * @throws TooManyConfigurationsException the account is at the cap
     */
    public function duplicateConfiguration(AiProviderSettings $source): AiProviderSettings
    {
        $user = $source->getUser();

        if ($this->aiProviderSettings->countForUser($user) >= self::MAX_CONFIGURATIONS) {
            throw new TooManyConfigurationsException(
                'This account already holds the maximum number of AI configurations.',
            );
        }

        $copy = $this->configurationFactory->duplicate($source, $this->credentials($source));
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
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

    /**
     * @throws AiKeyUnreadableException
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
     * The one place that opens the sealed key; public so every caller of the provider reuses it. An unreadable
     * key becomes the AI module's own exception, so it can never read as another module's secret.
     *
     * @throws AiKeyUnreadableException
     */
    public function credentials(AiProviderSettings $settings): ProviderCredentialsModel
    {
        try {
            $apiKey = $this->cipher->open($settings->getUser()->requireId(), $settings->getSealedSecret());
        } catch (SecretUnreadableException $exception) {
            throw new AiKeyUnreadableException('The stored API key cannot be opened.', previous: $exception);
        }

        return ProviderCredentialsModel::fromStoredConfiguration($settings->getBaseUrl(), $apiKey);
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
     * @throws AiKeyUnreadableException
     * @throws CredentialsRejectedException
     * @throws ModelNotOfferedException
     * @throws ProviderUnreachableException
     */
    private function assertModelStillOffered(AiProviderSettings $settings, string $model): ModelDescriptorModel
    {
        $offered = $this->catalog->listModels($this->credentials($settings));

        return $this->offeredDescriptor($offered, $model);
    }

    /**
     * @param list<ModelDescriptorModel> $offered
     */
    private function offeredDescriptor(array $offered, string $model): ModelDescriptorModel
    {
        foreach ($offered as $descriptor) {
            if ($descriptor->id === $model) {
                return $descriptor;
            }
        }

        throw new ModelNotOfferedException(sprintf('That provider does not offer "%s".', $model));
    }

    /**
     * @param list<ModelDescriptorModel> $descriptors
     *
     * @return list<string>
     */
    private function ids(array $descriptors): array
    {
        return array_map(static fn (ModelDescriptorModel $descriptor): string => $descriptor->id, $descriptors);
    }
}
