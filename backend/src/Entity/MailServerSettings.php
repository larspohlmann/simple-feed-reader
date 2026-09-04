<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\SealedMailPassword;
use App\Service\Mail\Settings\MailConnection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The instance-wide outgoing-mail settings, held in a single row (see
 * InstanceSetting for the singleton rationale). Absence of the row means "not
 * configured": enablement then derives from the env fallback. The password is
 * never readable here; passwordHint is the last four characters in clear text so
 * the admin page can name the stored secret.
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

    #[ORM\Column(length: 8)]
    private string $passwordHint = '';

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

    public function getPasswordHint(): string
    {
        return $this->passwordHint;
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

    public function getSealedPassword(): SealedMailPassword
    {
        return new SealedMailPassword(
            $this->passwordCiphertext,
            $this->passwordNonce,
            $this->passwordSalt,
            $this->keyVersion,
        );
    }

    public function apply(MailConnection $connection, SealedMailPassword $sealed, string $passwordHint): void
    {
        $this->applyWithoutPassword($connection);
        $this->passwordCiphertext = $sealed->ciphertext;
        $this->passwordNonce = $sealed->nonce;
        $this->passwordSalt = $sealed->salt;
        $this->keyVersion = $sealed->version;
        $this->passwordHint = $passwordHint;
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
}
