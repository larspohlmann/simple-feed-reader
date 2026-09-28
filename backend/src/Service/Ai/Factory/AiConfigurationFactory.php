<?php

declare(strict_types=1);

namespace App\Service\Ai\Factory;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Model\ProviderCredentialsModel;
use Psr\Clock\ClockInterface;

final readonly class AiConfigurationFactory
{
    private const int HINT_LENGTH = 4;
    private const int NAME_MAX_LENGTH = 120; // matches AiProviderSettings::$name column length

    public function __construct(private ApiKeyCipher $cipher, private ClockInterface $clock)
    {
    }

    public function create(User $user, ?string $name, ProviderCredentialsModel $credentials): AiProviderSettings
    {
        return new AiProviderSettings(
            $user,
            $name,
            $credentials->baseUrl,
            $this->cipher->seal($user->requireId(), $credentials->apiKey),
            substr($credentials->apiKey, -self::HINT_LENGTH),
            $this->clock->now(),
        );
    }

    public function duplicate(
        AiProviderSettings $source,
        ProviderCredentialsModel $sourceCredentials,
    ): AiProviderSettings {
        $user = $source->getUser();
        $copy = new AiProviderSettings(
            $user,
            self::copyName($source->getName()),
            $source->getBaseUrl(),
            $this->cipher->seal($user->requireId(), $sourceCredentials->apiKey),
            $source->getApiKeyHint(),
            $source->getVerifiedAt() ?? $this->clock->now(),
        );
        $copy->setSuppressReasoning($source->suppressesReasoning());
        $copy->copyRunTuningFrom($source);

        return $copy;
    }

    /**
     * The `name` column holds 120 characters, so a long source name is trimmed
     * to keep the prefixed copy inside it.
     */
    private static function copyName(?string $sourceName): string
    {
        if (null === $sourceName || '' === $sourceName) {
            return 'Copy';
        }

        return mb_substr('Copy of ' . $sourceName, 0, self::NAME_MAX_LENGTH);
    }
}
