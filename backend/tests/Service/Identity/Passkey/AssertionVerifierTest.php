<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Passkey\AssertionVerifier;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\Exception\AssertionRejectedException;
use App\Service\Passkey\Exception\UnknownPasskeyCredentialException;
use App\Service\Passkey\Factory\AssertionOptionsFactory;
use App\Service\Passkey\Model\PasskeyAttestationModel;
use App\Service\Passkey\PasskeyCeremony;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Service\Passkey\PasskeySignInAvailability;
use App\Tests\Support\PasskeyAttestationFixture;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\SeedsUsers;
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

/**
 * AssertionVerifier's unit coverage, chiefly the counter-rejection log line, easier to pin on a hand-built logger
 * than through HTTP (PasskeyLoginTest covers the firewall). Credentials are enrolled through the real
 * AttestationVerifier, so the stored bytes are exactly what registration persists.
 */
final class AssertionVerifierTest extends KernelTestCase
{
    use PinsPasskeyRelyingParty;
    use SeedsUsers;

    private const string RELYING_PARTY_ID = 'example.test';
    private const string ORIGIN = 'https://example.test';

    /**
     * Re-reads after clearing the identity map, so only a flushed row can satisfy the lookup: the returned entity is
     * the same managed object whether or not verify() flushed.
     */
    public function testAValidAssertionRecordsTheNewCounterAndTimestamp(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->user('login@example.test');
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

        $this->expectException(UnknownPasskeyCredentialException::class);

        $this->verifier()->verify($handle, $credential);
    }

    /**
     * An attestation response posted as an assertion must be refused by the type guard. The credential is enrolled,
     * since resolveCredential() would otherwise refuse it first for an unrelated reason.
     */
    public function testAnAttestationResponseSubmittedAsAnAssertionIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->user('wrong-response-type@example.test');
        $enrolled = $this->enrol($user, signCount: 0);
        $handle = $this->issueLoginChallenge($enrolled->challenge);

        $this->expectException(AssertionRejectedException::class);

        $this->verifier()->verify($handle, $enrolled->credential);
    }

    /**
     * A counter that goes backwards is rejected and logged with the credential id and user id an incident response
     * needs, asserted on a Monolog TestHandler.
     */
    public function testABackwardsCounterIsRejectedAndLogsAWarning(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->user('clone-victim@example.test');
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

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    /**
     * Clears the identity map first, so the repository lookup that follows
     * can only be satisfied by a real database row — see
     * testAValidAssertionRecordsTheNewCounterAndTimestamp's docblock.
     */
    private function rereadFromDatabase(User $user): UserPasskey
    {
        $this->entityManager()->clear();

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

        // issue() takes the base64url text every stored identifier uses, not the fixture's raw bytes.
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
            new PasskeyAttestationModel($handle, $fixture->credential, 'Test key'),
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
        /** @var PasskeySignInAvailability $availability */
        $availability = self::getContainer()->get(PasskeySignInAvailability::class);

        return new AssertionVerifier(
            $challengeStore,
            $ceremony,
            $optionsFactory,
            $passkeys,
            $this->entityManager(),
            new NaiveUtcClock($clock ?? new MockClock()),
            $logger ?? new NullLogger(),
            $availability,
        );
    }
}
