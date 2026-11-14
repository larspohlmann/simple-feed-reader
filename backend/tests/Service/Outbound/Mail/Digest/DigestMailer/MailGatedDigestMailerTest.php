<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Mail\Digest\DigestMailer;

use App\Service\Outbound\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Outbound\Mail\Digest\DigestMailer\MailGatedDigestMailer;
use App\Service\Outbound\Mail\Digest\Model\DigestModel;
use App\Service\Outbound\Mail\MailCapability;
use App\Service\Outbound\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Tests\Support\UnsavedUserSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MailGatedDigestMailerTest extends TestCase
{
    public function testDelegatesWhenMailEnabled(): void
    {
        $model = new DigestModel([], 0);
        $settings = UnsavedUserSettings::digest();

        $inner = $this->createMock(DigestMailerInterface::class);
        $inner->expects($this->once())->method('send')->with($settings, $model);

        $gated = new MailGatedDigestMailer($inner, $this->mailCapability(true), new NullLogger());
        $gated->send($settings, $model);
    }

    public function testSkipsAndDoesNotDelegateWhenMailDisabled(): void
    {
        $inner = $this->createMock(DigestMailerInterface::class);
        $inner->expects($this->never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with(
            'Mail disabled; skipped digest mail to {email}.',
            ['email' => 'reader@example.com'],
        );

        $gated = new MailGatedDigestMailer($inner, $this->mailCapability(false), $logger);
        $gated->send(UnsavedUserSettings::digest(), new DigestModel([], 0));
    }

    private function mailCapability(bool $enabled): MailCapability
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn($enabled);

        return new MailCapability($settings);
    }
}
