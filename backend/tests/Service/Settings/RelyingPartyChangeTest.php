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

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('example.test', 'https://evilexample.test'));
    }

    /** The genuine article: a real subdomain, one dot away from the relying party id. */
    public function testARealSubdomainOfTheRelyingPartyIdIsAccepted(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://reader.example.test');
        $this->expectNotToPerformAssertions();

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('example.test', 'https://reader.example.test'));
    }

    /**
     * `settings.instance.passkeyHelp.rule2` (both locales) promises a public
     * suffix is refused. A full public-suffix list is out of scope, but a
     * bare, single-label TLD like this one is unambiguous.
     */
    public function testASingleLabelRelyingPartyIdIsRefused(): void
    {
        $change = $this->change(
            currentRelyingPartyId: 'reader.example.com',
            publicBaseUrl: 'https://reader.example.com',
        );

        $this->expectException(ValidationException::class);

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('com', 'https://reader.example.com'));
    }

    /**
     * `settings.instance.passkeyHelp.rule3` (both locales) promises an IP
     * address is refused outright, with `localhost` the one exception — even
     * when, as here, the id exactly matches the host the reader is served
     * from.
     */
    public function testAnIpAddressRelyingPartyIdIsRefusedEvenWhenItExactlyMatchesTheHost(): void
    {
        $change = $this->change(currentRelyingPartyId: '203.0.113.5', publicBaseUrl: 'https://203.0.113.5');

        $this->expectException(ValidationException::class);

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('203.0.113.5', 'https://203.0.113.5'));
    }

    /**
     * The bug this closes: a fragment of the host's own IP literal used to
     * pass isSuffixOf()'s dot-boundary check exactly like a real subdomain
     * would, because an IP has no notion of a "subdomain" the way a DNS name
     * does.
     */
    public function testAFragmentOfAnIpHostIsRefused(): void
    {
        $change = $this->change(currentRelyingPartyId: '192.168.1.5', publicBaseUrl: 'https://192.168.1.5');

        $this->expectException(ValidationException::class);

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('1.5', 'https://192.168.1.5'));
    }

    /** Development depends on this: rule3's one named exception must still work. */
    public function testLocalhostIsAccepted(): void
    {
        $change = $this->change(currentRelyingPartyId: 'localhost', publicBaseUrl: 'https://localhost');
        $this->expectNotToPerformAssertions();

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('localhost', 'https://localhost'));
    }

    public function testTheValidationErrorNamesTheFieldAndExplainsTheRule(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://evilexample.test');

        try {
            $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('example.test', 'https://evilexample.test'));
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
