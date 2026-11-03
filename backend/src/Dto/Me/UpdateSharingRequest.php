<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Entity\Preferences;
use App\Service\Account\Model\SharingSettingsModel;
use Symfony\Component\Validator\Constraints as Assert;

/** No defaults: an empty body must never switch sharing off or unhide every target. */
final readonly class UpdateSharingRequest
{
    /** @param list<string> $hiddenTargets */
    public function __construct(
        public bool $enabled,
        #[Assert\Count(max: Preferences::MAX_HIDDEN_SHARE_TARGETS)]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(),
            new Assert\Regex(Preferences::TARGET_ID_PATTERN),
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
