<?php

declare(strict_types=1);

namespace App\Service\Mail\AccountMailer;

use App\Entity\User;
use App\Service\Mail\PendingApprovalNotice;

interface AccountMailerInterface
{
    public function sendVerification(User $user, string $plainToken): void;

    public function sendApproved(User $user): void;

    public function sendPasswordReset(User $user, string $plainToken): void;

    public function sendPendingApprovalNotice(User $admin, PendingApprovalNotice $notice): void;
}
