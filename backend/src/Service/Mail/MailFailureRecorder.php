<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\MailKind;

/** Records the outcome of an outgoing-mail send: a failure, or a success that clears the log (#882). */
interface MailFailureRecorder
{
    public function recordFailure(MailKind $kind, string $recipient, string $error): void;

    public function recordSuccess(): void;
}
