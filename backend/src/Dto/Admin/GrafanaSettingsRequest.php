<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required, so a PUT that leaves one out is a 422, not a reset; a null URL falls back to the env
 * default. The token is an optional three-state intent: null keeps it, a string replaces it, `removeToken` clears it.
 */
final readonly class GrafanaSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $lokiPushUrl,
        #[Assert\Length(max: 255)]
        public ?string $lokiUsername,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $grafanaUrl,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $pyroscopePushUrl,
        #[Assert\Type('bool')]
        public bool $profilingEnabled,
        #[Assert\Length(max: 512)]
        public ?string $token = null,
        #[Assert\Type('bool')]
        public bool $removeToken = false,
    ) {
    }
}
