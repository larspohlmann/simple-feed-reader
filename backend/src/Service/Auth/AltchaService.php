<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\ValidationException;
use App\Service\Auth\Model\AltchaChallengeModel;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Self-hosted ALTCHA proof-of-work: challenge = sha256(salt . number), signature = hmac_sha256(challenge, key). The
 * HMAC proves we issued a challenge, so nothing is stored but the replay guard.
 */
final readonly class AltchaService
{
    private const string ALGORITHM = 'SHA-256';
    /**
     * The difficulty window, in sha256 iterations, sized by what the browser widget can afford. The floor is the
     * load-bearing half: without it an attacker solves only the cheapest of many challenges. Measurements, and when
     * the window may widen: docs/security.md#altcha-difficulty
     */
    private const int MIN_NUMBER = 100_000;
    private const int MAX_NUMBER = 200_000;
    private const int TTL_SECONDS = 3600;
    /**
     * Outlives the challenge, or a solution spent just before expiry could be replayed once its replay entry expired.
     * Unpinned by tests: MockClock cannot move the cache's `expiresAfter`.
     */
    private const int REPLAY_TTL_SECONDS = self::TTL_SECONDS + 600;

    public function __construct(
        #[Autowire('%env(ALTCHA_HMAC_KEY)%')]
        private string $hmacKey,
        private ClockInterface $clock,
        private CacheItemPoolInterface $altchaReplayCache,
    ) {
    }

    /**
     * @throws RandomException
     */
    public function createChallenge(): AltchaChallengeModel
    {
        $expires = $this->clock->now()->getTimestamp() + self::TTL_SECONDS;
        $salt = bin2hex(random_bytes(12)) . '?expires=' . $expires;
        $number = random_int(self::MIN_NUMBER, self::MAX_NUMBER);

        $challenge = hash('sha256', $salt . $number);

        return new AltchaChallengeModel(
            self::ALGORITHM,
            $challenge,
            $salt,
            hash_hmac('sha256', $challenge, $this->hmacKey),
            self::MAX_NUMBER,
        );
    }

    /**
     * @param string $payload base64-encoded JSON produced by the widget
     * @throws InvalidArgumentException
     */
    public function verify(string $payload): bool
    {
        $solution = $this->decode($payload);
        if (null === $solution) {
            return false;
        }

        ['algorithm' => $algorithm, 'challenge' => $challenge, 'number' => $number,
            'salt' => $salt, 'signature' => $signature] = $solution;

        if (self::ALGORITHM !== $algorithm) {
            return false;
        }

        // Order matters: check our signature before doing anything with the
        // salt, so a forged challenge never reaches the rest of the routine.
        if (!hash_equals(hash_hmac('sha256', $challenge, $this->hmacKey), $signature)) {
            return false;
        }

        // `number` is client-supplied. Outside the window it cannot come from a challenge we issued: refuse it
        // unhashed, or the floor would bind only honest clients.
        if ($number < self::MIN_NUMBER || $number > self::MAX_NUMBER) {
            return false;
        }

        if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
            return false;
        }

        if ($this->isExpired($salt)) {
            return false;
        }

        return $this->claimOnce($signature);
    }

    /**
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function requireSolved(string $payload): void
    {
        if (!$this->verify($payload)) {
            throw new ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']]);
        }
    }

    /**
     * @return array{algorithm: string, challenge: string, number: int, salt: string, signature: string}|null
     */
    private function decode(string $payload): ?array
    {
        $json = base64_decode($payload, true);
        if (false === $json) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return $this->extractSolution($decoded);
    }

    /**
     * Pulls the five fields the widget sends out of the decoded payload,
     * rejecting anything missing or of the wrong type. Split from decode() so
     * the base64/JSON unwrapping and the field validation each stay simple.
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return array{algorithm: string, challenge: string, number: int, salt: string, signature: string}|null
     */
    private function extractSolution(array $decoded): ?array
    {
        foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $key) {
            if (!isset($decoded[$key])) {
                return null;
            }
        }

        if (
            !\is_string($decoded['algorithm'])
            || !\is_string($decoded['challenge'])
            || !\is_string($decoded['salt'])
            || !\is_string($decoded['signature'])
            || !\is_int($decoded['number'])
        ) {
            return null;
        }

        return [
            'algorithm' => $decoded['algorithm'],
            'challenge' => $decoded['challenge'],
            'number' => $decoded['number'],
            'salt' => $decoded['salt'],
            'signature' => $decoded['signature'],
        ];
    }

    private function isExpired(string $salt): bool
    {
        $query = parse_url('?' . (parse_url($salt, \PHP_URL_QUERY) ?? ''), \PHP_URL_QUERY);
        parse_str(\is_string($query) ? $query : '', $queryParameters);

        $expires = $queryParameters['expires'] ?? null;
        if (!\is_string($expires) || !ctype_digit($expires)) {
            return true;
        }

        return $this->clock->now()->getTimestamp() > (int) $expires;
    }

    /**
     * A valid solution is worth exactly one use. The signature identifies the
     * challenge uniquely, so remembering it until expiry blocks replay.
     *
     * @throws InvalidArgumentException
     */
    private function claimOnce(string $signature): bool
    {
        $item = $this->altchaReplayCache->getItem('altcha_' . $signature);
        if ($item->isHit()) {
            return false;
        }

        $item->set(true);
        $item->expiresAfter(self::REPLAY_TTL_SECONDS);
        $this->altchaReplayCache->save($item);

        return true;
    }
}
