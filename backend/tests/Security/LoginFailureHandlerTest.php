<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LoginFailureHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

final class LoginFailureHandlerTest extends KernelTestCase
{
    /** @return iterable<string, array{?int, string}> */
    public static function lockouts(): iterable
    {
        yield 'three minutes left' => [3, '180'];
        yield 'no threshold' => [null, '60'];
        yield 'zero minutes left' => [0, '60'];
    }

    #[DataProvider('lockouts')]
    public function testALockoutReportsSymfonysMinutesAsSeconds(?int $minutes, string $retryAfter): void
    {
        $handler = self::getContainer()->get(LoginFailureHandler::class);
        self::assertInstanceOf(LoginFailureHandler::class, $handler);

        $response = $handler->onAuthenticationFailure(
            Request::create('/api/auth/login', 'POST', content: '{"email":"someone@example.test"}'),
            new TooManyLoginAttemptsAuthenticationException($minutes),
        );

        self::assertSame(429, $response->getStatusCode());
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
    }
}
