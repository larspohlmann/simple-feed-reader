<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Full-replace payload for the Grafana wiring. A null URL clears the override
 * and falls back to the env default; the token is a three-state intent: null
 * keeps the stored secret, a string replaces it, removeToken clears it. The
 * token is inbound-only, never echoed back.
 */
final readonly class GrafanaSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $lokiPushUrl = null,
        #[Assert\Length(max: 255)]
        public ?string $lokiUsername = null,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $grafanaUrl = null,
        #[Assert\Length(max: 512)]
        public ?string $token = null,
        #[Assert\Type('bool')]
        public bool $removeToken = false,
    ) {
    }
}
