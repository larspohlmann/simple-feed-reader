<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-wide Grafana wiring, held in a single row (see InstanceSetting for the
 * singleton rationale). Absence of the row means "use the env defaults / not
 * configured". The URLs are nullable overrides: null falls back to the env
 * default the installer writes for the local container. The token is never
 * readable here; only whether one is stored, and its last four characters,
 * cross to the admin page.
 */
#[ORM\Entity(repositoryClass: GrafanaSettingsRepository::class)]
#[ORM\Table(name: 'grafana_settings')]
class GrafanaSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'loki_push_url', length: 255, nullable: true)]
    private ?string $lokiPushUrl = null;

    #[ORM\Column(name: 'loki_username', length: 255, nullable: true)]
    private ?string $lokiUsername = null;

    #[ORM\Column(name: 'grafana_url', length: 255, nullable: true)]
    private ?string $grafanaUrl = null;

    #[ORM\Column(length: 1024)]
    private string $tokenCiphertext = '';

    #[ORM\Column(length: 64)]
    private string $tokenNonce = '';

    #[ORM\Column(length: 64)]
    private string $tokenSalt = '';

    #[ORM\Column(length: 8)]
    private string $tokenHint = '';

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion = 1;

    #[ORM\Column(name: 'profiling_enabled', options: ['default' => false])]
    private bool $profilingEnabled = false;

    #[ORM\Column(name: 'pyroscope_push_url', length: 255, nullable: true)]
    private ?string $pyroscopePushUrl = null;

    public function getLokiPushUrlOverride(): ?string
    {
        return $this->lokiPushUrl;
    }

    public function getLokiUsername(): ?string
    {
        return $this->lokiUsername;
    }

    public function getGrafanaUrlOverride(): ?string
    {
        return $this->grafanaUrl;
    }

    public function isProfilingEnabled(): bool
    {
        return $this->profilingEnabled;
    }

    public function getPyroscopePushUrlOverride(): ?string
    {
        return $this->pyroscopePushUrl;
    }

    public function hasToken(): bool
    {
        return '' !== $this->tokenCiphertext;
    }

    public function getTokenHint(): string
    {
        return $this->tokenHint;
    }

    public function getSealedToken(): SealedSecret
    {
        return new SealedSecret($this->tokenCiphertext, $this->tokenNonce, $this->tokenSalt, $this->keyVersion);
    }

    public function apply(GrafanaConnection $connection, SealedSecret $sealed, string $tokenHint): void
    {
        $this->applyWithoutToken($connection);
        $this->tokenCiphertext = $sealed->ciphertext;
        $this->tokenNonce = $sealed->nonce;
        $this->tokenSalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
        $this->tokenHint = $tokenHint;
    }

    public function applyWithoutToken(GrafanaConnection $connection): void
    {
        $this->lokiPushUrl = $connection->lokiPushUrl;
        $this->lokiUsername = $connection->lokiUsername;
        $this->grafanaUrl = $connection->grafanaUrl;
        $this->pyroscopePushUrl = $connection->pyroscopePushUrl;
        $this->profilingEnabled = $connection->profilingEnabled;
    }

    public function clearStoredToken(): void
    {
        $this->tokenCiphertext = '';
        $this->tokenNonce = '';
        $this->tokenSalt = '';
        $this->tokenHint = '';
        $this->keyVersion = 1;
    }
}
