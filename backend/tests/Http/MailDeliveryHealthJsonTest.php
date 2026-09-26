<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Http\MailDeliveryHealthJson;
use PHPUnit\Framework\TestCase;

final class MailDeliveryHealthJsonTest extends TestCase
{
    public function testAFailureMapsItsErrorAndTimestamp(): void
    {
        $failure = new MailSendFailure(
            MailKind::Digest,
            'reader@example.test',
            'SMTP is down',
            new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
        );

        self::assertSame(
            [
                'failures' => [
                    [
                        'kind' => 'digest',
                        'recipient' => 'reader@example.test',
                        'error' => 'SMTP is down',
                        'at' => '2026-01-02T03:04:05+00:00',
                    ],
                ],
            ],
            MailDeliveryHealthJson::view([$failure]),
        );
    }
}
