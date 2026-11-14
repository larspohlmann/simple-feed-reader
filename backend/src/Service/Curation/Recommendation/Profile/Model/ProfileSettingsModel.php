<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

use App\Entity\AiProviderSettings;
use App\Entity\ProfileSettingsValues;
use App\Entity\StoredProfile;

/** Everything the profile section shows: the profile, its settings, and which connections can build it. */
final readonly class ProfileSettingsModel
{
    /** @param list<AiProviderSettings> $candidates */
    public function __construct(
        public StoredProfile $storedProfile,
        public ProfileSettingsValues $values,
        public ?AiProviderSettings $effectiveConnection,
        public array $candidates,
        public bool $debugEnabled,
    ) {
    }
}
