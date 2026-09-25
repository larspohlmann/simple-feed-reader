<?php

declare(strict_types=1);

namespace App\Service\Proxy;

final readonly class ProxyTestResult
{
    private function __construct(
        public bool $ok,
        public ?string $egressIp,
        public ?ProxyTestFailure $failure,
        public ?string $detail,
    ) {
    }

    public static function ok(string $egressIp): self
    {
        return new self(true, $egressIp, null, null);
    }

    public static function failed(ProxyTestFailure $failure, ?string $detail = null): self
    {
        return new self(false, null, $failure, $detail);
    }

    /** @return array{ok: bool, egressIp: string|null, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'egressIp' => $this->egressIp, 'reason' => $this->detail ?? $this->failure?->value];
    }
}
