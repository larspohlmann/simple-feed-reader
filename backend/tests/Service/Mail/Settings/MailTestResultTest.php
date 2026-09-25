<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Service\Mail\Settings\MailTestFailure;
use App\Service\Mail\Settings\MailTestResult;
use PHPUnit\Framework\TestCase;

final class MailTestResultTest extends TestCase
{
    public function testAGuardFailureSendsItsCode(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => 'not_configured'],
            MailTestResult::failed(MailTestFailure::NotConfigured)->toArray(),
        );
    }

    public function testARejectedSendSendsTheTransportMessage(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => '535 5.7.8 bad credentials'],
            MailTestResult::failed(MailTestFailure::SendRejected, '535 5.7.8 bad credentials')->toArray(),
        );
    }

    public function testSuccessSendsNoReason(): void
    {
        self::assertSame(['ok' => true, 'reason' => null], MailTestResult::ok()->toArray());
    }
}
