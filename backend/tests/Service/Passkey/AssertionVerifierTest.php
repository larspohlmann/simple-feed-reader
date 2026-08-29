<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\AssertionVerifier;
use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Passkey\AttestationVerifier;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * AssertionVerifier's own unit-level coverage (#624 Task 10). The full
 * end-to-end proof — through the real firewall, throttling included — lives
 * in PasskeyLoginTest; this file exists specifically because the
 * counter-rejection log line is far easier to pin down against a hand-built
 * Logger here than through the whole HTTP stack.
 *
 * Every enrolled credential in this file goes through the REAL
 * AttestationVerifier rather than a hand-assembled UserPasskey row: the
 * stored public key, credential id and user handle are all
 * base64url-encoded library output, and re-deriving those bytes by hand here
 * would risk quietly disagreeing with what registration actually persists.
 */
final class AssertionVerifierTest extends KernelTestCase
{
    use PinsPasskeyRelyingParty;

    private const string RELYING_PARTY_ID = 'example.test';
    private const string ORIGIN = 'https://example.test';

    public function testAValidAssertionRecordsTheNewCounterAndTimestamp(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->createUser('login@example.test');
        $enrolled = $this->enrol($user, signCount: 3);

        $now = new \DateTimeImmutable('2026-08-29T09:00:00Z');
        $handle = $this->issueLoginChallenge($enrolled->challenge);
        $credential = PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $enrolled->challenge,
            $enrolled,
            signCount: 7,
        );

        $stored = $this->verifier(clock: new MockClock($now))->verify($handle, $credential);

        self::assertSame($user->getId(), $stored->getUser()->getId());
        self::assertSame(7, $stored->getSignatureCounter());
        self::assertEquals($now, $stored->getLastUsedAt());
    }

    public function testAnUnenrolledCredentialIdIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $neverEnrolled = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );
        $handle = $this->issueLoginChallenge($neverEnrolled->challenge);
        $credential = PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $neverEnrolled->challenge,
            $neverEnrolled,
        );

        $this->expectException(AssertionRejectedException::class);

        $this->verifier()->verify($handle, $credential);
    }

    /**
     * The scenario the brief calls out by name: a counter that goes
     * backwards must be rejected AND logged, with the credential id and the
     * user id an incident response would need — asserted through a real
     * Monolog TestHandler, not by eyeballing a message string.
     */
    public function testABackwardsCounterIsRejectedAndLogsAWarning(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->createUser('clone-victim@example.test');
        $enrolled = $this->enrol($user, signCount: 5);
        $handle = $this->issueLoginChallenge($enrolled->challenge);
        $credential = PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $enrolled->challenge,
            $enrolled,
            signCount: 3,
        );

        $logSpy = new TestHandler();

        try {
            $this->verifier(logger: new Logger('test', [$logSpy]))->verify($handle, $credential);
            self::fail('Expected AssertionRejectedException.');
        } catch (AssertionRejectedException) {
            // Expected — the assertion under test is the log below.
        }

        self::assertTrue($logSpy->hasWarningThatContains('signature counter did not advance'));
        $record = $logSpy->getRecords()[0];
        self::assertSame($this->storedCredentialId($user), $record->context['credentialId']);
        self::assertSame($user->getId(), $record->context['userId']);
    }

    private function createUser(string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($em, $hasher))->create($email);
    }

    /**
     * Enrols a fresh credential for $user through the real registration
     * ceremony — see the class docblock — and returns the fixture so the
     * caller can sign a later assertion over the same identity.
     */
    private function enrol(User $user, int $signCount): PasskeyAttestationFixture
    {
        $fixture = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
            $signCount,
        );

        // PasskeyChallengeStore::issue() expects the base64url TEXT form —
        // the storage convention every stored identifier follows — not the
        // fixture's raw bytes; RegistrationOptionsFactory decodes it back
        // immediately (Base64UrlSafe::decodeNoPadding()) to build the
        // options' user entity.
        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);
        $handle = $store->issue(
            $fixture->challenge,
            $user->getId(),
            Base64UrlSafe::encodeUnpadded($fixture->userHandle),
        );

        /** @var AttestationVerifier $attestationVerifier */
        $attestationVerifier = self::getContainer()->get(AttestationVerifier::class);
        $attestationVerifier->verifyAndStore(
            $user,
            new RegisterPasskeyRequest($handle, $fixture->credential, 'Test key'),
        );

        return $fixture;
    }

    private function issueLoginChallenge(string $challenge): string
    {
        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);

        return $store->issue($challenge, userId: null, userHandle: null);
    }

    private function storedCredentialId(User $user): string
    {
        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        $stored = $repository->findForUser($user);
        self::assertCount(1, $stored);

        return $stored[0]->getCredentialId();
    }

    private function verifier(?ClockInterface $clock = null, ?Logger $logger = null): AssertionVerifier
    {
        /** @var PasskeyChallengeStore $challengeStore */
        $challengeStore = self::getContainer()->get(PasskeyChallengeStore::class);
        /** @var PasskeyCeremony $ceremony */
        $ceremony = self::getContainer()->get(PasskeyCeremony::class);
        /** @var UserPasskeyRepository $passkeys */
        $passkeys = self::getContainer()->get(UserPasskeyRepository::class);

        return new AssertionVerifier(
            $challengeStore,
            $ceremony,
            $passkeys,
            $clock ?? new MockClock(),
            $logger ?? new NullLogger(),
        );
    }
}
