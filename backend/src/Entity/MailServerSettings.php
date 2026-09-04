<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use App\Service\Crypto\SealedSecret;
use App\Service\Mail\Settings\MailConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-wide outgoing-mail settings in one row (singleton, see InstanceSetting).
 * No row means "not configured", so enablement derives from the env fallback.
 * The password never leaves here; only whether one is stored crosses to the admin page.
 */
#[ORM\Entity(repositoryClass: MailServerSettingsRepository::class)]
#[ORM\Table(name: 'mail_server_settings')]
class MailServerSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => 0])]
    private bool $enabled = false;

    #[ORM\Column(length: 255)]
    private string $host = '';

    #[ORM\Column]
    private int $port = MailConnection::DEFAULT_PORT;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 16, enumType: MailEncryption::class)]
    private MailEncryption $encryption = MailEncryption::Starttls;

    #[ORM\Column(length: 255)]
    private string $fromAddress = '';

    #[ORM\Column(length: 255)]
    private string $fromName = '';

    #[ORM\Column(length: 1024)]
    private string $passwordCiphertext = '';

    #[ORM\Column(length: 64)]
    private string $passwordNonce = '';

    #[ORM\Column(length: 64)]
    private string $passwordSalt = '';

    #[ORM\Column(options: ['default' => 1])]
    private int $keyVersion = 1;

    public function isEnabled(): bool
    {
        return $this->enabled;
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

    public function getEncryption(): MailEncryption
    {
        return $this->encryption;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function getFromName(): string
    {
        return $this->fromName;
    }

    public function connection(): MailConnection
    {
        return new MailConnection(
            $this->enabled,
            $this->host,
            $this->port,
            $this->username,
            $this->encryption,
            $this->fromAddress,
            $this->fromName,
        );
    }

    public function hasPassword(): bool
    {
        return '' !== $this->passwordCiphertext;
    }

    public function getSealedPassword(): SealedSecret
    {
        return new SealedSecret(
            $this->passwordCiphertext,
            $this->passwordNonce,
            $this->passwordSalt,
            $this->keyVersion,
        );
    }

    public function apply(MailConnection $connection, SealedSecret $sealed): void
    {
        $this->applyWithoutPassword($connection);
        $this->passwordCiphertext = $sealed->ciphertext;
        $this->passwordNonce = $sealed->nonce;
        $this->passwordSalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
    }

    public function applyWithoutPassword(MailConnection $connection): void
    {
        $this->enabled = $connection->enabled;
        $this->host = $connection->host;
        $this->port = $connection->port;
        $this->username = $connection->username;
        $this->encryption = $connection->encryption;
        $this->fromAddress = $connection->fromAddress;
        $this->fromName = $connection->fromName;
    }

    public function clearStoredPassword(): void
    {
        $this->passwordCiphertext = '';
        $this->passwordNonce = '';
        $this->passwordSalt = '';
        $this->keyVersion = 1;
    }
}
