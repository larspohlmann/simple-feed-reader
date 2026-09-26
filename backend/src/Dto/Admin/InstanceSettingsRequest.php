<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Http\FullReplacePayload;
use App\Service\Settings\InstanceSettingsUpdate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without which a
 * missing nullable setting reads as null. A null URL or relying-party field restores its derived default.
 * `invalidateExistingPasskeys` is no setting: it confirms an id change refused with 409.
 */
final readonly class InstanceSettingsRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireEmailConfirmation,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireApproval,
        #[Assert\Url(requireTld: false)]
        #[Assert\Length(max: 255)]
        public ?string $publicBaseUrl,
        #[Assert\Length(max: 255)]
        public ?string $passkeyRpId,
        #[Assert\Length(max: 100)]
        public ?string $passkeyRpName,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $passkeySignInEnabled,
        public bool $invalidateExistingPasskeys = false,
    ) {
    }

    public function toUpdate(): InstanceSettingsUpdate
    {
        return new InstanceSettingsUpdate(
            requireEmailConfirmation: $this->requireEmailConfirmation,
            requireApproval: $this->requireApproval,
            publicBaseUrl: $this->publicBaseUrl,
            passkeyRpId: $this->passkeyRpId,
            passkeyRpName: $this->passkeyRpName,
            passkeySignInEnabled: $this->passkeySignInEnabled,
        );
    }
}
