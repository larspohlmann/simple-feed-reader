<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\Passkey\AssertionOptionsFactory;
use App\Service\Passkey\AssertionVerifier;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
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

    /**
     * Fix round 1 (#624 Task 10): asserting on the object verify() RETURNS
     * proves nothing about persistence — it is the SAME managed entity
     * whether or not anything was ever flushed to the database. This test
     * clears the entity manager's identity map before re-reading, so a
     * repository lookup afterwards can only be satisfied by a real row,
     * never by Doctrine handing back the in-memory mutated object. Confirmed
     * this actually catches a missing flush: temporarily removed the
     * `$this->em->flush()` call from AssertionVerifier::verify() and re-ran
     * this test — it failed (the re-fetched row still had counter 3, not
     * 7) — before restoring it. See task-10-report.md's "Fix round 1"
     * section for the removal experiment's real output.
     */
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

        $rehydrated = $this->rereadFromDatabase($user);
        self::assertSame(7, $rehydrated->getSignatureCounter());
        self::assertEquals($now, $rehydrated->getLastUsedAt());
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
     * parse() deserializes the wire payload through the SAME PublicKeyCredential
     * class an attestation (registration) response uses; only the runtime type
     * of ->response tells the two apart. Submitting an attestation-shaped
     * credential — the exact payload a registration ceremony would send —
     * to the login endpoint must still be rejected cleanly, not let a
     * differently-typed response fall through to code that expects an
     * AuthenticatorAssertionResponse.
     *
     * The credential MUST already be enrolled: resolveCredential() runs
     * BEFORE the response is ever passed to checkAssertion(), so an
     * unenrolled credential id would reject the request for an unrelated
     * reason and never actually exercise the type guard this test is for.
     */
    public function testAnAttestationResponseSubmittedAsAnAssertionIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->createUser('wrong-response-type@example.test');
        $enrolled = $this->enrol($user, signCount: 0);
        $handle = $this->issueLoginChallenge($enrolled->challenge);

        $this->expectException(AssertionRejectedException::class);

        $this->verifier()->verify($handle, $enrolled->credential);
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

        $record = $this->warningRecordContaining($logSpy, 'signature counter did not advance');
        self::assertSame($this->storedCredentialId($user), $record->context['credentialId']);
        self::assertSame($user->getId(), $record->context['userId']);
    }

    /**
     * Selects the record BY MESSAGE rather than assuming it is the first one
     * captured — see PasskeyLoginTest's identical helper for the same
     * reasoning.
     */
    private function warningRecordContaining(TestHandler $logSpy, string $needle): LogRecord
    {
        foreach ($logSpy->getRecords() as $record) {
            if (Level::Warning === $record->level && str_contains($record->message, $needle)) {
                return $record;
            }
        }

        self::fail(\sprintf('No warning log record contains "%s".', $needle));
    }

    private function createUser(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    /**
     * Clears the identity map first, so the repository lookup that follows
     * can only be satisfied by a real database row — see
     * testAValidAssertionRecordsTheNewCounterAndTimestamp's docblock.
     */
    private function rereadFromDatabase(User $user): UserPasskey
    {
        $this->em()->clear();

        /** @var UserPasskeyRepository $repository */
        $repository = self::getContainer()->get(UserPasskeyRepository::class);
        $stored = $repository->findForUser($user);
        self::assertCount(1, $stored);

        return $stored[0];
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
        /** @var AssertionOptionsFactory $optionsFactory */
        $optionsFactory = self::getContainer()->get(AssertionOptionsFactory::class);
        /** @var UserPasskeyRepository $passkeys */
        $passkeys = self::getContainer()->get(UserPasskeyRepository::class);

        return new AssertionVerifier(
            $challengeStore,
            $ceremony,
            $optionsFactory,
            $passkeys,
            $this->em(),
            $clock ?? new MockClock(),
            $logger ?? new NullLogger(),
        );
    }
}
