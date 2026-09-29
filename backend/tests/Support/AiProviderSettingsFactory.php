<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\SealedSecret;
use App\Entity\User;

/**
 * An AiProviderSettings with a dummy sealed key and hint, for tests that need just a configuration. Construction only:
 * persisting, flushing, choosing a model and the active pointer differ per caller, so each call site does them.
 */
final class AiProviderSettingsFactory
{
    private const string DEFAULT_BASE_URL = 'https://api.example.test/v1';

    public static function build(
        User $user,
        ?string $name = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?\DateTimeImmutable $verifiedAt = null,
    ): AiProviderSettings {
        return new AiProviderSettings(
            $user,
            $name,
            $baseUrl,
            new SealedSecret('c', 'n', 's', 1),
            'ab12',
            $verifiedAt ?? new \DateTimeImmutable('2026-08-09T09:00:00Z'),
        );
    }
}
