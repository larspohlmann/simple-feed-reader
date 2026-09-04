<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class MailConnectionTesterTest extends KernelTestCase
{
    private function tester(): MailConnectionTester
    {
        return self::getContainer()->get(MailConnectionTester::class);
    }

    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    /** The tester mails the acting admin's own address, so it needs a
     *  logged-in user in the security token — none of the other kernel tests
     *  in this suite reach that branch, they all stop at "not_configured". */
    private function authenticateAsAdmin(): void
    {
        $factory = new UserFactory(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(UserPasswordHasherInterface::class),
        );
        $admin = $factory->create('boss@example.com', roles: ['ROLE_ADMIN']);

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = self::getContainer()->get('security.token_storage');
        $tokenStorage->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
    }

    public function testItReportsNotConfiguredWithNoSavedRow(): void
    {
        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertSame('not_configured', $result->reason);
    }

    public function testItReportsTheTransportErrorWhenTheServerIsUnreachable(): void
    {
        $this->settings()->update(
            new MailSettingsRequest(host: '127.0.0.1', port: 0, fromAddress: 'from@x.test', password: 'p'),
        );

        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertNotNull($result->reason);
    }

    public function testItReportsNoFromAddressForABlankIdentityInsteadOfThrowing(): void
    {
        // MAIL_FROM defaults to a real address in .env, so a saved row with a
        // blank from-address would still resolve through the env fallback.
        // Blanking it here proves the guard, not the env default.
        putenv('MAIL_FROM=');
        $_ENV['MAIL_FROM'] = '';
        $_SERVER['MAIL_FROM'] = '';

        $this->authenticateAsAdmin();
        $this->settings()->update(
            new MailSettingsRequest(enabled: true, host: 'smtp.relay.test', fromAddress: '', password: 'p'),
        );

        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertSame('no_from_address', $result->reason);
    }

    protected function tearDown(): void
    {
        putenv('MAIL_FROM=noreply@example.com');
        $_ENV['MAIL_FROM'] = 'noreply@example.com';
        $_SERVER['MAIL_FROM'] = 'noreply@example.com';

        parent::tearDown();
    }
}
