<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\InsecureProductionConfigGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

final class InsecureProductionConfigGuardTest extends TestCase
{
    private const SAFE_KEY = 'a-real-long-random-production-secret';

    private function guard(string $environment, string $altchaKey): InsecureProductionConfigGuard
    {
        return new InsecureProductionConfigGuard($environment, $altchaKey);
    }

    private function request(InsecureProductionConfigGuard $guard, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $guard->onKernelRequest(new RequestEvent(
            $this->createStub(KernelInterface::class),
            Request::create('/api/health'),
            $type,
        ));
    }

    public function testProdWithTheCommittedAltchaKeyIsRefused(): void
    {
        $problems = $this->guard(
            'prod',
            InsecureProductionConfigGuard::PLACEHOLDER_ALTCHA_HMAC_KEY,
        )->problems();

        self::assertCount(1, $problems);
        self::assertStringContainsString('ALTCHA_HMAC_KEY', $problems[0]);
    }

    public function testProdWithRealValuesIsAccepted(): void
    {
        self::assertSame([], $this->guard('prod', self::SAFE_KEY)->problems());
    }

    /**
     * dev and test depend on exactly this default — the suite solves real
     * ALTCHA challenges with the committed key. A guard that fired here would
     * make the project unusable rather than safer.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonProductionEnvironments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'test' => ['test'];
    }

    #[DataProvider('nonProductionEnvironments')]
    public function testNonProductionEnvironmentsAreNeverRefused(string $environment): void
    {
        $guard = $this->guard(
            $environment,
            InsecureProductionConfigGuard::PLACEHOLDER_ALTCHA_HMAC_KEY,
        );

        self::assertSame([], $guard->problems());

        // And the listener itself stays silent: no exception escapes.
        $this->request($guard);
    }

    public function testAMisconfiguredProdRequestThrows(): void
    {
        $guard = $this->guard(
            'prod',
            InsecureProductionConfigGuard::PLACEHOLDER_ALTCHA_HMAC_KEY,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ALTCHA_HMAC_KEY/');

        $this->request($guard);
    }

    public function testAWellConfiguredProdRequestPassesThrough(): void
    {
        $this->expectNotToPerformAssertions();

        $this->request($this->guard('prod', self::SAFE_KEY));
    }

    /**
     * Sub-requests (a forward, an ESI fragment) must not re-run the check. The
     * main request already answered it, and throwing from a sub-request would
     * turn one refusal into a confusing nested failure.
     */
    public function testSubRequestsAreIgnored(): void
    {
        $this->expectNotToPerformAssertions();

        $this->request(
            $this->guard(
                'prod',
                InsecureProductionConfigGuard::PLACEHOLDER_ALTCHA_HMAC_KEY,
            ),
            HttpKernelInterface::SUB_REQUEST,
        );
    }

    /**
     * The value this guards against must be the one actually committed to
     * .env. If someone edits .env without editing the guard, the guard silently
     * stops matching and prod fails open again — the exact bug, reintroduced
     * invisibly. This is the only assertion here that reads the real file.
     */
    public function testTheGuardedLiteralsStillMatchDotEnv(): void
    {
        $dotEnv = file_get_contents(\dirname(__DIR__, 2) . '/.env');
        self::assertIsString($dotEnv);

        self::assertStringContainsString(
            'ALTCHA_HMAC_KEY=' . InsecureProductionConfigGuard::PLACEHOLDER_ALTCHA_HMAC_KEY,
            $dotEnv,
            'the guarded ALTCHA placeholder no longer matches .env',
        );
    }
}
