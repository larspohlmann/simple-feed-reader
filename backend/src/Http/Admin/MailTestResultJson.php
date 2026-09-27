<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Mail\Settings\MailTestResult;

final class MailTestResultJson
{
    /** @return array{ok: bool, reason: string|null} */
    public static function from(MailTestResult $result): array
    {
        return ['ok' => $result->ok, 'reason' => $result->detail ?? $result->failure?->value];
    }
}
