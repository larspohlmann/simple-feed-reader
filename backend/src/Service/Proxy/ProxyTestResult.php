<?php

declare(strict_types=1);

namespace App\Service\Proxy;

final readonly class ProxyTestResult
{
    private function __construct(
        public bool $ok,
        public ?string $egressIp,
        public ?string $reason,
    ) {
    }

    public static function ok(string $egressIp): self
    {
        return new self(true, $egressIp, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }

    /** @return array{ok: bool, egressIp: string|null, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'egressIp' => $this->egressIp, 'reason' => $this->reason];
    }
}
