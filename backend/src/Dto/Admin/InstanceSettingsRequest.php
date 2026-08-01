<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class InstanceSettingsRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireEmailConfirmation = true,
        #[Assert\NotNull]
        #[Assert\Type('bool')]
        public bool $requireApproval = true,
    ) {
    }
}
