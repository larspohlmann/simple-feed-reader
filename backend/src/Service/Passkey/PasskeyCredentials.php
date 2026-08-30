<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Entity\UserPasskey;
use App\Repository\UserPasskeyRepository;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Random\RandomException;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * Reads and mints the WebAuthn identifiers a registration ceremony needs from
 * this account's existing credentials (#624).
 *
 * `UserPasskey::$credentialId` and `$userHandle` are stored base64url-encoded
 * — MySQL-safe text, not raw binary, the same reason `PasskeyChallengeStore`
 * mints its handles that way. Every value this class hands to the WebAuthn
 * library is therefore decoded back to raw bytes first; the library's own
 * serializer re-encodes it once more for the wire, the identical round trip
 * {@see \Webauthn\Denormalizer\PublicKeyCredentialDescriptorNormalizer} and
 * {@see \Webauthn\Denormalizer\PublicKeyCredentialUserEntityDenormalizer}
 * already perform for every other WebAuthn byte field.
 */
final readonly class PasskeyCredentials
{
    private const int HANDLE_LENGTH_BYTES = 32;

    public function __construct(private UserPasskeyRepository $repository)
    {
    }

    /**
     * Every credential a user owns must carry the same handle, so an
     * account with at least one existing credential gets that one back
     * rather than a fresh mint. This is the only place a handle is minted.
     *
     * @throws RandomException
     */
    public function userHandleFor(User $user): string
    {
        $credentials = $this->repository->findForUser($user);

        if ([] !== $credentials) {
            return $credentials[0]->getUserHandle();
        }

        return self::randomHandle();
    }

    /**
     * Names every authenticator already enrolled on this account, so the
     * browser can silently refuse one of them instead of enrolling a
     * duplicate credential.
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    public function excludeListFor(User $user): array
    {
        return array_map(
            self::toDescriptor(...),
            $this->repository->findForUser($user),
        );
    }

    private static function toDescriptor(UserPasskey $credential): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            Base64UrlSafe::decodeNoPadding($credential->getCredentialId()),
            $credential->getTransports(),
        );
    }

    /**
     * @throws RandomException
     */
    private static function randomHandle(): string
    {
        return Base64UrlSafe::encodeUnpadded(random_bytes(self::HANDLE_LENGTH_BYTES));
    }
}
