<?php

declare(strict_types=1);

namespace App\Service\Grafana\Model;

use App\Entity\GrafanaConnection;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Entity\SealedSecret;

/**
 * The Grafana row as plain values, for the admin page, the runtime reads and GrafanaSettingsCache. The cache entry is
 * flat scalars, so an entry from an earlier release either reads back or is rejected as a miss.
 */
final readonly class GrafanaSettingsSnapshotModel
{
    public function __construct(
        public GrafanaConnection $connection,
        public SealedSecret $sealedToken,
        public string $tokenHint,
    ) {
    }

    public static function fromEntity(GrafanaSettingsEntity $entity): self
    {
        return new self($entity->connection(), $entity->getSealedToken(), $entity->getTokenHint());
    }

    public function hasToken(): bool
    {
        return '' !== $this->sealedToken->ciphertext;
    }

    /** @return array<string, string|bool|int|null> */
    public function toCacheEntry(): array
    {
        return [
            'lokiPushUrl' => $this->connection->lokiPushUrl,
            'lokiUsername' => $this->connection->lokiUsername,
            'grafanaUrl' => $this->connection->grafanaUrl,
            'pyroscopePushUrl' => $this->connection->pyroscopePushUrl,
            'profilingEnabled' => $this->connection->profilingEnabled,
            'tokenCiphertext' => $this->sealedToken->ciphertext,
            'tokenNonce' => $this->sealedToken->nonce,
            'tokenSalt' => $this->sealedToken->salt,
            'tokenKeyVersion' => $this->sealedToken->version,
            'tokenHint' => $this->tokenHint,
        ];
    }

    public static function fromCacheEntryOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }

        $connection = self::connectionFromArrayOrNull($stored);
        $sealedToken = self::sealedTokenFromArrayOrNull($stored);
        $tokenHint = $stored['tokenHint'] ?? null;
        if (null === $connection || null === $sealedToken || !\is_string($tokenHint)) {
            return null;
        }

        return new self($connection, $sealedToken, $tokenHint);
    }

    /** @param array<array-key, mixed> $stored */
    private static function connectionFromArrayOrNull(array $stored): ?GrafanaConnection
    {
        $lokiPushUrl = $stored['lokiPushUrl'] ?? null;
        $lokiUsername = $stored['lokiUsername'] ?? null;
        $grafanaUrl = $stored['grafanaUrl'] ?? null;
        $pyroscopePushUrl = $stored['pyroscopePushUrl'] ?? null;
        $profilingEnabled = $stored['profilingEnabled'] ?? null;
        if (
            !self::isNullableString($lokiPushUrl)
            || !self::isNullableString($lokiUsername)
            || !self::isNullableString($grafanaUrl)
            || !self::isNullableString($pyroscopePushUrl)
            || !\is_bool($profilingEnabled)
        ) {
            return null;
        }

        return new GrafanaConnection($lokiPushUrl, $lokiUsername, $grafanaUrl, $pyroscopePushUrl, $profilingEnabled);
    }

    /** @param array<array-key, mixed> $stored */
    private static function sealedTokenFromArrayOrNull(array $stored): ?SealedSecret
    {
        $ciphertext = $stored['tokenCiphertext'] ?? null;
        $nonce = $stored['tokenNonce'] ?? null;
        $salt = $stored['tokenSalt'] ?? null;
        $keyVersion = $stored['tokenKeyVersion'] ?? null;
        if (!\is_string($ciphertext) || !\is_string($nonce) || !\is_string($salt) || !\is_int($keyVersion)) {
            return null;
        }

        return new SealedSecret($ciphertext, $nonce, $salt, $keyVersion);
    }

    /** @phpstan-assert-if-true string|null $value */
    private static function isNullableString(mixed $value): bool
    {
        return null === $value || \is_string($value);
    }
}
