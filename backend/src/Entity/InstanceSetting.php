<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstanceSettingRepository;
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

    public function apply(bool $requireEmailConfirmation, bool $requireApproval, ?string $publicBaseUrl): void
    {
        $this->requireEmailConfirmation = $requireEmailConfirmation;
        $this->requireApproval = $requireApproval;
        $this->publicBaseUrl = $publicBaseUrl;
    }
}
