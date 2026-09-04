<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

final readonly class MailTestResult
{
    private function __construct(
        public bool $ok,
        public ?string $reason,
    ) {
    }

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @return array{ok: bool, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'reason' => $this->reason];
    }
}
