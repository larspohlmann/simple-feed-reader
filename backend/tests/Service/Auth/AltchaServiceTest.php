<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Service\Auth\AltchaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AltchaServiceTest extends TestCase
{
    private const HMAC_KEY = 'test-hmac-key';

    private MockClock $clock;
    private AltchaService $service;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-21 12:00:00');
        $this->service = new AltchaService(self::HMAC_KEY, $this->clock, new ArrayAdapter());
    }

    /** Solve the challenge the way the browser widget would. */
    private function solve(string $salt, int $maxNumber, string $challenge): int
    {
        for ($number = 0; $number <= $maxNumber; ++$number) {
            if (hash('sha256', $salt . $number) === $challenge) {
                return $number;
            }
        }

        self::fail('Challenge was not solvable within maxnumber');
    }

    private function payloadFor(int $number, string $salt, string $challenge, string $signature): string
    {
        return base64_encode((string) json_encode([
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'number' => $number,
            'salt' => $salt,
            'signature' => $signature,
        ]));
    }

    public function testChallengeIsWellFormed(): void
    {
        $challenge = $this->service->createChallenge();

        self::assertSame('SHA-256', $challenge->algorithm);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge->challenge);
        self::assertStringContainsString('expires=', $challenge->salt);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge->signature);
        self::assertGreaterThan(0, $challenge->maxNumber);
    }

    public function testChallengeIsSignedWithTheHmacKey(): void
    {
        $challenge = $this->service->createChallenge();

        self::assertSame(
            hash_hmac('sha256', $challenge->challenge, self::HMAC_KEY),
            $challenge->signature,
        );
    }

    public function testAcceptsACorrectSolution(): void
    {
        $challenge = $this->service->createChallenge();
        $number = $this->solve($challenge->salt, $challenge->maxNumber, $challenge->challenge);

        $payload = $this->payloadFor($number, $challenge->salt, $challenge->challenge, $challenge->signature);

        self::assertTrue($this->service->verify($payload));
    }

    public function testRejectsAWrongNumber(): void
    {
        $challenge = $this->service->createChallenge();
        $number = $this->solve($challenge->salt, $challenge->maxNumber, $challenge->challenge);

        $payload = $this->payloadFor($number + 1, $challenge->salt, $challenge->challenge, $challenge->signature);

        self::assertFalse($this->service->verify($payload));
    }

    public function testRejectsAForgedSignature(): void
    {
        // The attack this blocks: mint your own easy challenge and solve it.
        $salt = 'abc123?expires=' . ($this->clock->now()->getTimestamp() + 600);
        $number = 3;
        $challenge = hash('sha256', $salt . $number);

        $payload = $this->payloadFor($number, $salt, $challenge, hash_hmac('sha256', $challenge, 'wrong-key'));

        self::assertFalse($this->service->verify($payload));
    }

    public function testRejectsAnExpiredChallenge(): void
    {
        $challenge = $this->service->createChallenge();
        $number = $this->solve($challenge->salt, $challenge->maxNumber, $challenge->challenge);
        $payload = $this->payloadFor($number, $challenge->salt, $challenge->challenge, $challenge->signature);

        $this->clock->modify('+2 hours');

        self::assertFalse($this->service->verify($payload));
    }

    public function testRejectsAReplayedSolution(): void
    {
        $challenge = $this->service->createChallenge();
        $number = $this->solve($challenge->salt, $challenge->maxNumber, $challenge->challenge);
        $payload = $this->payloadFor($number, $challenge->salt, $challenge->challenge, $challenge->signature);

        self::assertTrue($this->service->verify($payload));
        // Without this, one solved challenge would authorise unlimited signups.
        self::assertFalse($this->service->verify($payload));
    }

    /**
     * `number` arrives from the client and is concatenated into a hash input.
     * A number outside the advertised range cannot have come from solving the
     * challenge we issued, so it is refused before it is hashed at all.
     *
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeNumberProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'far negative' => [\PHP_INT_MIN];
        yield 'above maxnumber' => [50_001];
        yield 'enormous' => [\PHP_INT_MAX];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRangeNumberProvider')]
    public function testRejectsANumberOutsideTheAdvertisedRange(int $number): void
    {
        $challenge = $this->service->createChallenge();

        // Sign a challenge that genuinely is sha256(salt . number) for the
        // out-of-range number, so only the range check can reject it.
        $salt = $challenge->salt;
        $forged = hash('sha256', $salt . $number);
        $payload = $this->payloadFor($number, $salt, $forged, hash_hmac('sha256', $forged, self::HMAC_KEY));

        self::assertFalse($this->service->verify($payload));
    }

    public function testRejectsMalformedPayloads(): void
    {
        self::assertFalse($this->service->verify(''));
        self::assertFalse($this->service->verify('not-base64!!'));
        self::assertFalse($this->service->verify(base64_encode('not json')));
        self::assertFalse($this->service->verify(base64_encode('{"challenge":"x"}')));
    }

    public function testRejectsAnUnsupportedAlgorithm(): void
    {
        $challenge = $this->service->createChallenge();
        $number = $this->solve($challenge->salt, $challenge->maxNumber, $challenge->challenge);

        $payload = base64_encode((string) json_encode([
            'algorithm' => 'MD5',
            'challenge' => $challenge->challenge,
            'number' => $number,
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
        ]));

        self::assertFalse($this->service->verify($payload));
    }
}
