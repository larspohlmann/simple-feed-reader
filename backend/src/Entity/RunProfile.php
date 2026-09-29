<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The reader-preference profile distilled for one run, frozen at distillation. Its own copy, not the settings'
 * profile_text (the display copy), so a run whose distillation degraded never reads the last run's profile.
 */
#[ORM\Embeddable]
final class RunProfile
{
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profileText = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $distilled = false;

    public function record(?string $profileText): void
    {
        $this->profileText = $profileText;
        $this->distilled = true;
    }

    public function getProfileText(): ?string
    {
        return $this->profileText;
    }

    public function isDistilled(): bool
    {
        return $this->distilled;
    }
}
