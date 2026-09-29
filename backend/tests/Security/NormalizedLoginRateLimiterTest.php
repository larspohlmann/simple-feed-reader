<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\NormalizedLoginRateLimiter;
use App\Tests\Support\RecordingLoginRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class NormalizedLoginRateLimiterTest extends TestCase
{
    private const string PADDED_USERNAME = '  Bob@Example.COM ';

    /**
     * @return iterable<string, array{string}>
     */
    public static function limiterOperations(): iterable
    {
        yield 'peek' => ['peek'];
        yield 'consume' => ['consume'];
        yield 'reset' => ['reset'];
    }

    #[DataProvider('limiterOperations')]
    public function testTheInnerLimiterIsHandedTheNormalizedUsername(string $operation): void
    {
        $inner = new RecordingLoginRateLimiter();
        $request = new Request();
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, self::PADDED_USERNAME);

        (new NormalizedLoginRateLimiter($inner))->{$operation}($request);

        self::assertSame(
            [$operation => User::normalizeEmail(self::PADDED_USERNAME)],
            $inner->lastUsernameSeenBy,
        );
    }
}
