<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Dto\Passkey\RegisterPasskeyRequest;
use App\Entity\User;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * AttestationVerifier's own unit-level coverage (#624). PasskeyRegistrationTest
 * already proves the ceremony end to end through the real firewall; this file
 * exists for the handful of checks a full HTTP round trip cannot easily pin:
 * the two library-response type guards, the AAGUID round trip, and the
 * "a registration challenge must always carry a user handle" invariant.
 */
final class AttestationVerifierTest extends KernelTestCase
{
    use PinsPasskeyRelyingParty;

    private const string RELYING_PARTY_ID = 'example.test';
    private const string ORIGIN = 'https://example.test';

    /**
     * deserialize() distinguishes an attestation from an assertion response
     * purely by the deserialized object's runtime type. An assertion-shaped
     * credential — the exact payload a LOGIN ceremony would send — must still
     * be rejected cleanly when submitted to the REGISTRATION endpoint,
     * mirroring AssertionVerifierTest's converse case.
     */
    public function testAnAssertionResponseSubmittedAsAnAttestationIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->createUser('wrong-shape@example.test');
        $enrolled = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );
        $assertionShapedCredential = PasskeyFixtures::assertion(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            $enrolled->challenge,
            $enrolled,
        );
        $handle = $this->issueRegistrationChallenge($enrolled->challenge, $user, $enrolled->userHandle);

        $this->expectException(AttestationRejectedException::class);

        $this->verifier()->verifyAndStore(
            $user,
            new RegisterPasskeyRequest($handle, $assertionShapedCredential, 'My phone'),
        );
    }

    /**
     * check()'s null-coalesce guard defends an invariant no request-reachable
     * caller can violate today: RegistrationOptionsFactory always pairs a
     * non-null user id with a non-null user handle, and AssertionOptionsFactory
     * pairs null with null (see PasskeyChallengeStore's own docblock) — so a
     * registration challenge with a MATCHING user id but a null user handle
     * cannot arise through either options endpoint. PasskeyChallengeStore's
     * own issue() signature does not enforce that pairing, though, so this
     * calls it directly to construct exactly that otherwise-unreachable
     * shape, proving the guard fires rather than silently mishandling it.
     */
    public function testARegistrationChallengeWithNoUserHandleIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->createUser('no-handle@example.test');
        $fixture = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
        );

        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);
        $handle = $store->issue($fixture->challenge, $user->getId(), userHandle: null);

        $this->expectException(\UnexpectedValueException::class);

        $this->verifier()->verifyAndStore(
            $user,
            new RegisterPasskeyRequest($handle, $fixture->credential, 'My phone'),
        );
    }

    /**
     * Every other test in this feature enrols against PasskeyFixtures' fixed
     * all-zero AAGUID (the spec's "none assigned" sentinel), which
     * aaguidOrNull() normalises to a stored null — so none of them can tell
     * the ternary is the right way round. This pins the other branch: a real,
     * non-nil AAGUID must round-trip to its RFC 4122 string, not to null.
     */
    public function testARealAaguidIsStoredAsItsRfc4122String(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->createUser('real-aaguid@example.test');
        $aaguid = random_bytes(16);
        $fixture = PasskeyFixtures::attestation(
            self::RELYING_PARTY_ID,
            self::ORIGIN,
            random_bytes(32),
            random_bytes(16),
            random_bytes(32),
            aaguid: $aaguid,
        );
        $handle = $this->issueRegistrationChallenge($fixture->challenge, $user, $fixture->userHandle);

        $stored = $this->verifier()->verifyAndStore(
            $user,
            new RegisterPasskeyRequest($handle, $fixture->credential, 'My phone'),
        );

        self::assertSame(Uuid::fromBinary($aaguid)->toRfc4122(), $stored->getAaguid());
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

    private function issueRegistrationChallenge(string $challenge, User $user, string $userHandle): string
    {
        /** @var PasskeyChallengeStore $store */
        $store = self::getContainer()->get(PasskeyChallengeStore::class);

        return $store->issue($challenge, $user->getId(), Base64UrlSafe::encodeUnpadded($userHandle));
    }

    private function verifier(): AttestationVerifier
    {
        /** @var AttestationVerifier $verifier */
        $verifier = self::getContainer()->get(AttestationVerifier::class);

        return $verifier;
    }
}
