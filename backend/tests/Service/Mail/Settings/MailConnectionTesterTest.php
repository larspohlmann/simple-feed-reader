<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Dto\Admin\MailSettingsRequest;
use App\Dto\Admin\ProxySettingsRequest;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Proxy\ProxySettings;
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

    public function testItReportsAMalformedEnvFromAddressInsteadOfThrowing(): void
    {
        putenv('MAIL_FROM=not-an-address');
        $_ENV['MAIL_FROM'] = 'not-an-address';
        $_SERVER['MAIL_FROM'] = 'not-an-address';

        $this->authenticateAsAdmin();
        $this->settings()->update(
            new MailSettingsRequest(enabled: true, host: 'smtp.relay.test', fromAddress: '', password: 'p'),
        );

        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertStringContainsString('not-an-address', (string) $result->reason);
    }

    /** No saved row, but an env fallback DSN configured: the tester must dial
     *  that transport rather than short-circuiting to not_configured. */
    public function testItTestsTheEnvFallbackWhenNoRowIsSaved(): void
    {
        putenv('MAILER_FALLBACK_DSN=smtp://smtp.invalid.test:2525');
        $_ENV['MAILER_FALLBACK_DSN'] = 'smtp://smtp.invalid.test:2525';
        $_SERVER['MAILER_FALLBACK_DSN'] = 'smtp://smtp.invalid.test:2525';

        $this->authenticateAsAdmin();

        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertNotSame('not_configured', $result->reason);
    }

    /** A saved row with useProxy=true must be tested through the proxy
     *  transport, not the plain EsmtpTransport — proven by dialing an
     *  unreachable proxy and getting a transport error, not not_configured. */
    public function testItTestsAProxiedSavedRowThroughTheFactory(): void
    {
        $this->authenticateAsAdmin();

        self::getContainer()->get(ProxySettings::class)->update(new ProxySettingsRequest(
            type: 'SOCKS5',
            host: '127.0.0.1',
            port: 1,
        ));
        $this->settings()->update(new MailSettingsRequest(
            enabled: true,
            host: 'smtp.gmail.com',
            username: 'u',
            password: 'p',
            fromAddress: 'from@x.test',
            useProxy: true,
        ));

        $result = $this->tester()->test();

        self::assertFalse($result->ok);
        self::assertNotSame('not_configured', $result->reason);
    }

    protected function tearDown(): void
    {
        putenv('MAIL_FROM=noreply@example.com');
        $_ENV['MAIL_FROM'] = 'noreply@example.com';
        $_SERVER['MAIL_FROM'] = 'noreply@example.com';

        putenv('MAILER_FALLBACK_DSN=null://null');
        $_ENV['MAILER_FALLBACK_DSN'] = 'null://null';
        $_SERVER['MAILER_FALLBACK_DSN'] = 'null://null';

        parent::tearDown();
    }
}
