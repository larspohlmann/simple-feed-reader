<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\MailKind;
use App\Service\Mail\MailFailureRecorder;

/** A MailFailureRecorder that captures calls in memory, for tests (#882). */
final class InMemoryMailFailureRecorder implements MailFailureRecorder
{
    /** @var list<array{kind: MailKind, recipient: string, error: string}> */
    private array $failures = [];

    private int $successCount = 0;

    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        $this->failures[] = ['kind' => $kind, 'recipient' => $recipient, 'error' => $error];
    }

    public function recordSuccess(): void
    {
        ++$this->successCount;
        $this->failures = [];
    }

    /** @return list<array{kind: MailKind, recipient: string, error: string}> */
    public function recordedFailures(): array
    {
        return $this->failures;
    }

    public function successCount(): int
    {
        return $this->successCount;
    }
}
