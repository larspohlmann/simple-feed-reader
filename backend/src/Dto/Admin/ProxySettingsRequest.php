<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\ProxyType;
use App\Http\FullReplacePayload;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every connection setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without
 * which a missing nullable setting reads as null. The password is an optional three-state intent: null keeps the
 * stored secret, a string replaces it, `removePassword` clears it.
 */
final readonly class ProxySettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled,
        #[Assert\Type('bool')]
        public bool $directFallback,
        #[Assert\Choice(choices: [ProxyType::Socks5->value, ProxyType::Http->value])]
        public string $type,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $host,
        #[Assert\Range(min: 1, max: 65535)]
        public int $port,
        #[Assert\Length(max: 255)]
        public ?string $username,
        #[Assert\Type('bool')]
        public bool $remoteDns,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }
}
