<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The reader-preference profile distilled for one recommendation run, frozen
 * at distillation time.
 *
 * Embedded into RecommendationRun rather than left as two of its own scalar
 * columns — PHPMD's field-count ceiling on RecommendationRun is a proxy for
 * a real seam here too, the same one ProviderUsage was extracted for (#409):
 * profileText and distilled are written together by recordProfile() and
 * belong to the same concern. Deliberately its own type rather than a reuse
 * of user_recommendation_settings.profile_text (Version20260821130000): that
 * column is the settings display copy, and this one is this run's own frozen
 * snapshot, so a degraded distillation this run never reads last run's
 * profile. The column names are unprefixed so the table itself is unchanged.
 */
#[ORM\Embeddable]
class RunProfile
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
