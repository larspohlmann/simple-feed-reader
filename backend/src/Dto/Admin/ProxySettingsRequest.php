<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Full-replace payload for the egress proxy. `#[MapRequestPayload]` fills a
 * missing field with the constructor default, so clients always send the whole
 * connection. `password` is the one exception to full-replace: null means "keep
 * the stored secret", a non-null string replaces it. It is inbound-only and is
 * never echoed back.
 */
final readonly class ProxySettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled = false,
        #[Assert\Type('bool')]
        public bool $directFallback = true,
        #[Assert\Choice(choices: ['SOCKS5', 'HTTP'])]
        public string $type = 'SOCKS5',
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $host = '',
        #[Assert\Range(min: 1, max: 65535)]
        public int $port = 1080,
        #[Assert\Length(max: 255)]
        public ?string $username = null,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
    ) {
    }
}
