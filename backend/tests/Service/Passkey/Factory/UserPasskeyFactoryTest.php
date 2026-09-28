<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey\Factory;

use App\Entity\User;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Passkey\Exception\AttestationRejectedException;
use App\Service\Passkey\Factory\UserPasskeyFactory;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\EmptyTrustPath;

final class UserPasskeyFactoryTest extends TestCase
{
    public function testTheCredentialIsStoredBase64UrlEncoded(): void
    {
        $passkey = $this->factory()->create($this->user(), $this->record('credential-id', ['usb']), 'My key');

        self::assertSame(Base64UrlSafe::encodeUnpadded('credential-id'), $passkey->getCredentialId());
        self::assertSame(Base64UrlSafe::encodeUnpadded('user-handle'), $passkey->getUserHandle());
        self::assertSame(7, $passkey->getSignatureCounter());
    }

    public function testAnIdThatFillsTheColumnExactlyIsStored(): void
    {
        $passkey = $this->factory()->create($this->user(), $this->record(str_repeat('x', 191), ['usb']), 'Full');

        self::assertSame(255, \strlen($passkey->getCredentialId()));
    }

    public function testAnIdTooLongForTheColumnIsRejectedBeforeTheWrite(): void
    {
        $this->expectException(AttestationRejectedException::class);

        $this->factory()->create($this->user(), $this->record(str_repeat('x', 192), ['usb']), 'Too long');
    }

    public function testTheNilAaguidIsStoredAsNoAaguid(): void
    {
        $passkey = $this->factory()->create($this->user(), $this->record('credential-id', ['usb']), 'Nil');

        self::assertNull($passkey->getAaguid());
    }

    public function testARealAaguidIsStoredAsItsRfc4122String(): void
    {
        $aaguid = Uuid::v4();

        $passkey = $this->factory()->create($this->user(), $this->record('credential-id', ['usb'], $aaguid), 'Real');

        self::assertSame($aaguid->toRfc4122(), $passkey->getAaguid());
    }

    public function testOnlyTheSpecsTransportsAreKept(): void
    {
        $record = $this->record('credential-id', ['usb', 'carrier-pigeon', 'nfc']);

        self::assertSame(['usb', 'nfc'], $this->factory()->create($this->user(), $record, 'Mixed')->getTransports());
    }

    private function factory(): UserPasskeyFactory
    {
        return new UserPasskeyFactory(new NaiveUtcClock(new MockClock('2026-08-01 12:00:00')));
    }

    private function user(): User
    {
        return new User('passkey-owner@example.test', new \DateTimeImmutable('2026-08-01 12:00:00'));
    }

    /** @param list<string> $transports */
    private function record(string $credentialId, array $transports, ?Uuid $aaguid = null): CredentialRecord
    {
        return CredentialRecord::create(
            $credentialId,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $transports,
            'none',
            EmptyTrustPath::create(),
            $aaguid ?? new NilUuid(),
            'public-key',
            'user-handle',
            7,
        );
    }
}
