<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Http\Admin\MailTestResultJson;
use App\Service\Mail\Settings\Model\MailTestFailure;
use App\Service\Mail\Settings\Model\MailTestResultModel;
use PHPUnit\Framework\TestCase;

final class MailTestResultJsonTest extends TestCase
{
    public function testAGuardFailureSendsItsCode(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => 'not_configured'],
            MailTestResultJson::from(MailTestResultModel::failed(MailTestFailure::NotConfigured)),
        );
    }

    public function testARejectedSendSendsTheTransportMessage(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => '535 5.7.8 bad credentials'],
            MailTestResultJson::from(
                MailTestResultModel::failed(MailTestFailure::SendRejected, '535 5.7.8 bad credentials'),
            ),
        );
    }

    public function testSuccessSendsNoReason(): void
    {
        self::assertSame(['ok' => true, 'reason' => null], MailTestResultJson::from(MailTestResultModel::ok()));
    }
}
