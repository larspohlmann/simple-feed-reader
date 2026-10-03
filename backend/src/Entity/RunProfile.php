<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The stored profile as this run froze it before its snapshot, so a later profile run never changes its scoring. */
#[ORM\Embeddable]
final class RunProfile
{
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profileText = null;

    public function freeze(?string $profileText): void
    {
        $this->profileText = $profileText;
    }

    public function getProfileText(): ?string
    {
        return $this->profileText;
    }
}
