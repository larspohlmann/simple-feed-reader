<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Dto\Admin\InstanceSettingsRequest;
use App\Exception\ValidationException;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\RelyingPartyChange;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;

/**
 * AdminSettingsControllerTest already proves the suffix guard end to end
 * (testAnRelyingPartyIdThatIsNotASuffixOfTheHostIsRefused) with an rpId that
 * shares no characters at all with the host — 'evil.test' against
 * 'lars-pohlmann.de'. That case cannot tell isSuffixOf()'s dot-boundary check
 * apart from a bare str_ends_with(): both agree it fails. This file pins the
 * one host shape where they would disagree.
 */
final class RelyingPartyChangeTest extends TestCase
{
    /**
     * 'evilexample.test' ends with the literal characters 'example.test'
     * with no separating dot — it is a different domain entirely, not a
     * subdomain, and must be refused exactly like an unrelated host would be.
     */
    public function testAHostThatMerelyEndsWithTheRelyingPartyIdStringIsRefused(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://evilexample.test');

        $this->expectException(ValidationException::class);

        $change->guard($this->requestFor('example.test', 'https://evilexample.test'));
    }

    /** The genuine article: a real subdomain, one dot away from the relying party id. */
    public function testARealSubdomainOfTheRelyingPartyIdIsAccepted(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://reader.example.test');
        $this->expectNotToPerformAssertions();

        $change->guard($this->requestFor('example.test', 'https://reader.example.test'));
    }

    public function testTheValidationErrorNamesTheFieldAndExplainsTheRule(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://evilexample.test');

        try {
            $change->guard($this->requestFor('example.test', 'https://evilexample.test'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(
                [
                    'passkeyRpId' => [
                        'Must be the host, or a registrable parent domain of the host, '
                        . 'that the reader is served from.',
                    ],
                ],
                $exception->errors,
            );
        }
    }

    private function requestFor(string $passkeyRpId, string $publicBaseUrl): InstanceSettingsRequest
    {
        return new InstanceSettingsRequest(
            publicBaseUrl: $publicBaseUrl,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: 'Reader',
        );
    }

    private function change(string $currentRelyingPartyId, string $publicBaseUrl): RelyingPartyChange
    {
        return new RelyingPartyChange(
            $this->relyingPartyOf($currentRelyingPartyId),
            new FixedPublicBaseUrl($publicBaseUrl),
            new EffectivePasskeyRelyingPartyId(),
            $this->createStub(UserPasskeyRepository::class),
        );
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
