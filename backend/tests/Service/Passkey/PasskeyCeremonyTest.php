<?php

declare(strict_types=1);

namespace App\Tests\Service\Passkey;

use App\Service\Passkey\PasskeyCeremony;
use App\Service\Settings\PasskeyRelyingParty;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\CeremonyStep\CeremonyStepManager;

final class PasskeyCeremonyTest extends TestCase
{
    /**
     * The origin comes from PublicBaseUrl, which reads the database, so the
     * managers cannot be built when the container is compiled. This test is
     * what stops somebody "simplifying" them into constructor-time state.
     */
    public function testTheCeremonyManagersAreBuiltFromTheRuntimeOrigin(): void
    {
        $ceremony = new PasskeyCeremony(
            $this->relyingPartyOf('lars-pohlmann.de'),
            new FixedPublicBaseUrl('https://lars-pohlmann.de/reader'),
        );

        self::assertSame('lars-pohlmann.de', $ceremony->host());
        self::assertInstanceOf(CeremonyStepManager::class, $ceremony->creation());
        self::assertInstanceOf(CeremonyStepManager::class, $ceremony->request());
    }

    public function testTheManagersAreBuiltOnceAndReused(): void
    {
        $ceremony = new PasskeyCeremony(
            $this->relyingPartyOf('localhost'),
            new FixedPublicBaseUrl('http://localhost:4200'),
        );

        self::assertSame($ceremony->creation(), $ceremony->creation());
        self::assertSame($ceremony->request(), $ceremony->request());
    }

    public function testTheSerializerIsBuiltFromTheLibraryFactory(): void
    {
        $ceremony = new PasskeyCeremony(
            $this->relyingPartyOf('localhost'),
            new FixedPublicBaseUrl('http://localhost:4200'),
        );

        self::assertInstanceOf(SerializerInterface::class, $ceremony->serializer());
    }

    private function relyingPartyOf(string $id): PasskeyRelyingParty
    {
        return new class ($id) implements PasskeyRelyingParty {
            public function __construct(private string $id)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function name(): string
            {
                return 'Simple Feed Reader';
            }
        };
    }
}
