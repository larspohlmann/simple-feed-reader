<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstanceSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-wide settings the admin edits at runtime, as typed columns in a single row rather than a key/value table.
 * No row means the defaults (see InstanceSettings), so a fresh database needs no seeding.
 */
#[ORM\Entity(repositoryClass: InstanceSettingRepository::class)]
#[ORM\Table(name: 'instance_setting')]
final class InstanceSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $requireEmailConfirmation = true;

    #[ORM\Column]
    private bool $requireApproval = true;

    /**
     * The externally reachable base URL used to build links in outgoing email.
     * Null means "no override" — email links fall back to the
     * APP_FRONTEND_URL deploy env. See {@see \App\Service\Settings\PublicBaseUrl\PublicBaseUrlInterface}.
     */
    #[ORM\Column(name: 'public_base_url', length: 255, nullable: true)]
    private ?string $publicBaseUrl = null;

    /**
     * Every passkey is bound to this relying-party id at registration, so changing it invalidates them all; the write
     * goes through {@see \App\Service\Settings\RelyingPartyChangeGuard}. Null takes the public base URL's host.
     */
    #[ORM\Column(name: 'passkey_rp_id', length: 255, nullable: true)]
    private ?string $passkeyRpId = null;

    /** What the authenticator's own UI shows; cosmetic, unlike passkeyRpId. Null means "Simple Feed Reader". */
    #[ORM\Column(name: 'passkey_rp_name', length: 100, nullable: true)]
    private ?string $passkeyRpName = null;

    /**
     * Passkey sign-in stays off until an admin opts in; PasskeySignInAvailability adds the relying-party check.
     * The same default sits in this column's DEFAULT and in InstanceSettingsUpdate's constructor: change all three.
     */
    #[ORM\Column(name: 'passkey_sign_in_enabled', options: ['default' => false])]
    private bool $passkeySignInEnabled = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function requireEmailConfirmation(): bool
    {
        return $this->requireEmailConfirmation;
    }

    public function requireApproval(): bool
    {
        return $this->requireApproval;
    }

    public function getPublicBaseUrl(): ?string
    {
        return $this->publicBaseUrl;
    }

    public function getPasskeyRpId(): ?string
    {
        return $this->passkeyRpId;
    }

    public function getPasskeyRpName(): ?string
    {
        return $this->passkeyRpName;
    }

    public function passkeySignInEnabled(): bool
    {
        return $this->passkeySignInEnabled;
    }

    public function apply(InstanceSettingsUpdate $update): void
    {
        $this->requireEmailConfirmation = $update->requireEmailConfirmation;
        $this->requireApproval = $update->requireApproval;
        $this->publicBaseUrl = $update->publicBaseUrl;
        $this->passkeyRpId = $update->passkeyRpId;
        $this->passkeyRpName = $update->passkeyRpName;
        $this->passkeySignInEnabled = $update->passkeySignInEnabled;
    }
}
