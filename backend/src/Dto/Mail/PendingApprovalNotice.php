<?php

declare(strict_types=1);

namespace App\Dto\Mail;

use App\Enum\RegistrationMethod;

/**
 * Everything an admin-approval notification needs, assembled once and reused for
 * every recipient. Only the locale differs per admin (resolved by AccountMailer
 * from the recipient), so the rest of the payload — applicant, method, count and
 * the review link — is identical across recipients and computed a single time.
 *
 * A DTO rather than a five-argument send method: it keeps
 * AccountMailer::sendPendingApprovalNotice() to two parameters.
 */
final readonly class PendingApprovalNotice
{
    public function __construct(
        public string $applicantEmail,
        public RegistrationMethod $method,
        public ?string $oauthProvider,
        public string $reviewUrl,
        public int $pendingApprovalCount,
    ) {
    }
}
