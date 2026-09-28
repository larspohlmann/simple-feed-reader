<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Proxy\Model\ProxyTestResultModel;

final class ProxyTestResultJson
{
    /** @return array{ok: bool, egressIp: string|null, reason: string|null} */
    public static function from(ProxyTestResultModel $result): array
    {
        return [
            'ok' => $result->ok,
            'egressIp' => $result->egressIp,
            'reason' => $result->detail ?? $result->failure?->value,
        ];
    }
}
