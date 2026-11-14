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
 * Reads and mints the WebAuthn identifiers a registration needs. Stored ids and handles are base64url text; every
 * value handed to the library is decoded to raw bytes first, and the library's serializer re-encodes it.
 */
final readonly class PasskeyCredentials
{
    private const int HANDLE_LENGTH_BYTES = 32;

    public function __construct(private UserPasskeyRepository $passkeys)
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
        return $this->sharedHandle($this->passkeys->findForUser($user)) ?? self::randomHandle();
    }

    /**
     * The one handle every row of an account carries, or null with no rows — never minted: the browser ignores a
     * handle that matches nothing.
     *
     * @param list<UserPasskey> $credentials
     */
    public function sharedHandle(array $credentials): ?string
    {
        return ($credentials[0] ?? null)?->getUserHandle();
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
            $this->passkeys->findForUser($user),
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
