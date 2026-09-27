<?php

declare(strict_types=1);

namespace App\Service\ClientError;

final readonly class ClientError
{
    public function __construct(
        public string $message,
        public ?string $stack,
        public ?string $kind,
        public ?string $url,
        public ?string $route,
        public ?string $buildVersion,
        public ?string $userAgent,
        public ?string $at,
    ) {
    }
}
