<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\AccountMailer;

use App\Service\Mail\AccountMailer\AccountMailerInterface;
use App\Service\Mail\AccountMailer\MailGatedAccountMailer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two classes implement AccountMailerInterface, so autowiring cannot alias it; services.yaml does, and a wrong target
 * would let every account mail bypass the mail gate. MailGatedAccountMailerTest builds the decorator by hand.
 */
final class MailGatedAccountMailerWiringTest extends KernelTestCase
{
    public function testInterfaceResolvesToTheDecorator(): void
    {
        self::bootKernel();
        $mailer = self::getContainer()->get(AccountMailerInterface::class);

        self::assertInstanceOf(MailGatedAccountMailer::class, $mailer);
    }
}
