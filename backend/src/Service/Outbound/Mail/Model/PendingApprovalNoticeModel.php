<?php

declare(strict_types=1);

namespace App\Service\Mail\Model;

use App\Enum\RegistrationMethod;

/** Built once per applicant and sent to every admin; AccountMailer adds each recipient's own locale. */
final readonly class PendingApprovalNoticeModel
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
