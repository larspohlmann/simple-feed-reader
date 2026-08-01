<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\AccountMailerInterface;
use App\Service\Mail\MailGatedAccountMailer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * MailGatedAccountMailerTest constructs the decorator by hand, so it can never
 * catch a wiring regression: two classes implement AccountMailerInterface
 * (AccountMailer and this decorator), which makes Symfony's automatic
 * interface-autowiring alias ambiguous and skip itself. Without the explicit
 * `App\Service\Mail\AccountMailerInterface: '@App\Service\Mail\AccountMailer'`
 * alias in services.yaml, every consumer that typehints the interface would
 * fail to build at all — or, worse, a future refactor could point the alias at
 * the wrong side and every account mail would silently start bypassing the
 * mail gate. This test drives the REAL container to prove the interface
 * resolves to the decorated chain, not the bare mailer.
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
