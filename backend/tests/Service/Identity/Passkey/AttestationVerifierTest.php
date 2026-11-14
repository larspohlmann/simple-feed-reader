<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Entity\User;
use App\Service\Passkey\AttestationVerifier;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Model\PasskeyAttestationModel;
use App\Service\Passkey\PasskeyChallengeStore;
use App\Tests\Support\PasskeyFixtures;
use App\Tests\Support\PinsPasskeyRelyingParty;
use App\Tests\Support\SeedsUsers;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What an HTTP round trip cannot easily pin: the two response-type guards, the AAGUID round trip, and a registration
 * challenge that must carry a user handle.
 */
final class AttestationVerifierTest extends KernelTestCase
{
    use PinsPasskeyRelyingParty;
    use SeedsUsers;

    private const string RELYING_PARTY_ID = 'example.test';
    private const string ORIGIN = 'https://example.test';

    /** An assertion-shaped credential posted to registration must be refused by deserialize()'s runtime-type check. */
    public function testAnAssertionResponseSubmittedAsAnAttestationIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->user('wrong-shape@example.test');
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
            new PasskeyAttestationModel($handle, $assertionShapedCredential, 'My phone'),
        );
    }

    /**
     * No options endpoint pairs a user id with a null handle, but issue() does not enforce the pairing: this builds
     * that shape directly to prove check()'s guard refuses it.
     */
    public function testARegistrationChallengeWithNoUserHandleIsRejected(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->user('no-handle@example.test');
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
            new PasskeyAttestationModel($handle, $fixture->credential, 'My phone'),
        );
    }

    /**
     * Every other test uses the all-zero AAGUID, stored as null; a real AAGUID must round-trip to its RFC 4122 string.
     */
    public function testARealAaguidIsStoredAsItsRfc4122String(): void
    {
        self::bootKernel();
        $this->pinRelyingParty(self::RELYING_PARTY_ID, 'Example Reader', self::ORIGIN);
        $user = $this->user('real-aaguid@example.test');
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
            new PasskeyAttestationModel($handle, $fixture->credential, 'My phone'),
        );

        self::assertSame(Uuid::fromBinary($aaguid)->toRfc4122(), $stored->getAaguid());
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
