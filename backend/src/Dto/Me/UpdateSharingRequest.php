<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Entity\SharingSettings;
use App\Service\Account\Model\SharingSettingsModel;
use Symfony\Component\Validator\Constraints as Assert;

/** No defaults: an empty body must never switch sharing off or unhide every target. */
final readonly class UpdateSharingRequest
{
    /** @param list<string> $hiddenTargets */
    public function __construct(
        public bool $enabled,
        #[Assert\Count(max: SharingSettings::MAX_HIDDEN_TARGETS)]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(),
            new Assert\Regex(SharingSettings::TARGET_ID_PATTERN),
        ])]
        public array $hiddenTargets,
        public bool $rememberShares,
    ) {
    }

    public function toSettings(): SharingSettingsModel
    {
        return new SharingSettingsModel($this->enabled, $this->hiddenTargets, $this->rememberShares);
    }
}
