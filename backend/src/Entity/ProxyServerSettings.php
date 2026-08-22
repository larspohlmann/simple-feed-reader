<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProxyType;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Proxy\Crypto\SealedProxyPassword;
use App\Service\Proxy\ProxyConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The instance-wide egress proxy, held in a single row (see InstanceSetting for
 * the singleton rationale). Absence of the row means "no proxy configured".
 * The password is never readable here: passwordHint is the last four characters
 * in clear text, on purpose, so the admin page can name the stored secret.
 */
#[ORM\Entity(repositoryClass: ProxyServerSettingsRepository::class)]
#[ORM\Table(name: 'proxy_server_settings')]
class ProxyServerSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    private bool $enabled = false;

    #[ORM\Column(options: ['default' => 1])]
    private bool $directFallback = true;

    #[ORM\Column(length: 8, enumType: ProxyType::class)]
    private ProxyType $type = ProxyType::Socks5;

    #[ORM\Column(length: 255)]
    private string $host = '';

    #[ORM\Column]
    private int $port = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 1024)]
    private string $passwordCiphertext = '';

    #[ORM\Column(length: 64)]
    private string $passwordNonce = '';

    #[ORM\Column(length: 64)]
    private string $passwordSalt = '';

    #[ORM\Column(length: 8)]
    private string $passwordHint = '';

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion = 1;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isDirectFallback(): bool
    {
        return $this->directFallback;
    }

    public function getType(): ProxyType
    {
        return $this->type;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPasswordHint(): string
    {
        return $this->passwordHint;
    }

    public function hasPassword(): bool
    {
        return '' !== $this->passwordCiphertext;
    }

    public function getSealedPassword(): SealedProxyPassword
    {
        return new SealedProxyPassword(
            $this->passwordCiphertext,
            $this->passwordNonce,
            $this->passwordSalt,
            $this->keyVersion,
        );
    }

    public function apply(ProxyConnection $connection, SealedProxyPassword $sealed, string $passwordHint): void
    {
        $this->applyConnection($connection);
        $this->passwordCiphertext = $sealed->ciphertext;
        $this->passwordNonce = $sealed->nonce;
        $this->passwordSalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
        $this->passwordHint = $passwordHint;
    }

    public function applyWithoutPassword(ProxyConnection $connection): void
    {
        $this->applyConnection($connection);
    }

    private function applyConnection(ProxyConnection $connection): void
    {
        $this->enabled = $connection->enabled;
        $this->directFallback = $connection->directFallback;
        $this->type = $connection->type;
        $this->host = $connection->host;
        $this->port = $connection->port;
        $this->username = $connection->username;
    }
}
