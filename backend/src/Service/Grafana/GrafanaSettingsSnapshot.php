<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Crypto\SealedSecret;

/**
 * A cache-safe copy of the Grafana singleton row. GrafanaSettingsCache stores
 * this across requests so the per-request profiling, Loki and Pyroscope reads
 * stop hitting the database. It carries only column values — no Doctrine
 * association — so it survives serialisation, and its array form is flat
 * scalars so a stored entry from an earlier release either reads back or is
 * rejected as a miss (a cache entry is never a source of truth; the row is).
 */
final readonly class GrafanaSettingsSnapshot
{
    public function __construct(
        private GrafanaConnection $connection,
        private SealedSecret $sealedToken,
        private string $tokenHint,
    ) {
    }

    public static function fromEntity(GrafanaSettingsEntity $entity): self
    {
        return new self(
            new GrafanaConnection(
                $entity->getLokiPushUrlOverride(),
                $entity->getLokiUsername(),
                $entity->getGrafanaUrlOverride(),
                $entity->getPyroscopePushUrlOverride(),
                $entity->isProfilingEnabled(),
            ),
            $entity->getSealedToken(),
            $entity->getTokenHint(),
        );
    }

    public function toEntity(): GrafanaSettingsEntity
    {
        $entity = new GrafanaSettingsEntity();
        if ('' === $this->sealedToken->ciphertext) {
            $entity->applyWithoutToken($this->connection);
        } else {
            $entity->apply($this->connection, $this->sealedToken, $this->tokenHint);
        }

        return $entity;
    }

    /** @return array<string, string|bool|int|null> */
    public function toArray(): array
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

    public static function fromArrayOrNull(mixed $stored): ?self
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
