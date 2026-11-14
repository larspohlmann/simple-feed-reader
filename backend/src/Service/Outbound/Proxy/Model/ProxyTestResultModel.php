<?php

declare(strict_types=1);

namespace App\Service\Proxy\Model;

final readonly class ProxyTestResultModel
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
}
