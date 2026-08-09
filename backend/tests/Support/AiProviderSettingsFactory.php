<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\SealedApiKey;

/**
 * Builds an AiProviderSettings with the dummy sealed key and hint that every
 * test needing just *a* configuration on the wire uses ('c'/'n'/'s'/1,
 * 'ab12') — none of them are exercising the seal or the hint format.
 *
 * Construction only, and static: two of its five call sites are plain
 * TestCases with no EntityManager at all, and among the rest whether the row
 * gets persisted immediately or batched, flushed once or per-row, or has its
 * model chosen or its owner's active pointer set afterward differs enough
 * that folding any of it in here would add options only one caller needs.
 * Each site keeps that step for itself.
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
            new SealedApiKey('c', 'n', 's', 1),
            'ab12',
            $verifiedAt ?? new \DateTimeImmutable('2026-08-09T09:00:00Z'),
        );
    }
}
