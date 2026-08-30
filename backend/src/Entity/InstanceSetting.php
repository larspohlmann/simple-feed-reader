<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstanceSettingRepository;
use App\Service\Settings\InstanceSettingsUpdate;
use Doctrine\ORM\Mapping as ORM;

/**
 * Instance-wide settings the admin edits at runtime, held in a single row.
 *
 * Deliberately NOT a key/value table: two typed booleans read and validate
 * without stringly-typed parsing, and PHPStan sees real types. A future flag
 * costs one nullable-safe migration, which is an honest price for that safety.
 * Absence of the row means "defaults" (see InstanceSettings), so a fresh
 * database needs no seeding.
 */
#[ORM\Entity(repositoryClass: InstanceSettingRepository::class)]
#[ORM\Table(name: 'instance_setting')]
class InstanceSetting
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
     * The externally reachable base URL used to build links in outgoing email
     * (#636). Null means "no override" — email links fall back to the
     * APP_FRONTEND_URL deploy env. See {@see \App\Service\Settings\PublicBaseUrl}.
     */
    #[ORM\Column(name: 'public_base_url', length: 255, nullable: true)]
    private ?string $publicBaseUrl = null;

    /**
     * The WebAuthn relying-party id every stored credential is bound to
     * (#624). Null means "no override" — {@see \App\Service\Settings\ConfiguredPasskeyRelyingParty}
     * derives it from the public base URL's host instead. It exists because
     * an RP id is baked into every credential at registration time: changing
     * it invalidates every passkey on the instance, which is why the write
     * path guards a change with {@see \App\Service\Settings\RelyingPartyChange}.
     */
    #[ORM\Column(name: 'passkey_rp_id', length: 255, nullable: true)]
    private ?string $passkeyRpId = null;

    /**
     * The relying-party display name shown by the authenticator's own UI.
     * Purely cosmetic — unlike passkeyRpId, changing it does not affect any
     * stored credential. Null means "no override", falling back to the
     * literal "Simple Feed Reader".
     */
    #[ORM\Column(name: 'passkey_rp_name', length: 100, nullable: true)]
    private ?string $passkeyRpName = null;

    /**
     * The instance-wide passkey sign-in switch (#624 follow-up). Defaults to
     * FALSE (addendum to the follow-up: the product owner reversed the
     * original true default) — "activated" means activated, so a fresh
     * install ships with passkey sign-in invisible until an admin opts in
     * from the instance settings page, even though the relying party would
     * derive correctly with no configuration at all. See
     * {@see \App\Service\Passkey\PasskeySignInAvailability}, which combines
     * this with the relying-party validity check.
     *
     * FIVE PLACES must agree on this default or a fresh install disagrees
     * with an existing one, or the no-row case disagrees with the column:
     * this property AND its `options: ['default' => ...]` below, the
     * migration's column DEFAULT, {@see \App\Service\Settings\InstanceSettings::passkeySignInEnabled()}'s
     * `??` fallback, and both {@see \App\Service\Settings\InstanceSettingsUpdate}
     * and {@see \App\Dto\Admin\InstanceSettingsRequest}'s constructor defaults.
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
