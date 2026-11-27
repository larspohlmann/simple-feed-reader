<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

use App\Entity\AiProviderSettings;
use App\Entity\User;

/** An account whose stored profile is newer than its interest terms, with what the refresh needs to read it. */
final readonly class DueInterestTermsModel
{
    public function __construct(
        public User $user,
        public AiProviderSettings $connection,
        public string $profileText,
        public \DateTimeImmutable $profileGeneratedAt,
    ) {
    }
}
