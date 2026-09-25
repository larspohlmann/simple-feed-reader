<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

final readonly class MailTestResult
{
    private function __construct(
        public bool $ok,
        public ?MailTestFailure $failure,
        public ?string $detail,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, null, null);
    }

    public static function failed(MailTestFailure $failure, ?string $detail = null): self
    {
        return new self(false, $failure, $detail);
    }

    /** @return array{ok: bool, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'reason' => $this->detail ?? $this->failure?->value];
    }
}
