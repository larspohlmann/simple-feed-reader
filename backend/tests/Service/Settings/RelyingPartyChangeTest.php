<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Dto\Admin\InstanceSettingsRequest;
use App\Exception\ValidationException;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\RelyingPartyChange;
use App\Service\Settings\RelyingPartyIdRule;
use App\Service\Settings\ServingHost;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;

final class RelyingPartyChangeTest extends TestCase
{
    /**
     * The domain is the admin's to choose: the server cannot know which origin
     * a browser reaches it on, so an id unrelated to anything it can see is
     * accepted and the browser enforces the real match.
     */
    public function testADomainUnrelatedToThisServerIsAccepted(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://localhost');
        $this->expectNotToPerformAssertions();

        $change->guardAndInvalidatePasskeysIfChanged(
            $this->requestFor('green-tara.aardvark-koi.ts.net', 'https://localhost'),
        );
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
     * address is refused outright, with `localhost` the one exception.
     */
    public function testAnIpAddressRelyingPartyIdIsRefused(): void
    {
        $change = $this->change(currentRelyingPartyId: '203.0.113.5', publicBaseUrl: 'https://203.0.113.5');

        $this->expectException(ValidationException::class);

        $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('203.0.113.5', 'https://203.0.113.5'));
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
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://example.test');

        try {
            $change->guardAndInvalidatePasskeysIfChanged($this->requestFor('com', 'https://example.test'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['passkeyRpId' => ['Must be a domain name, not an IP address or a bare top-level domain.']],
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
            new EffectivePasskeyRelyingPartyId(),
            $this->createStub(UserPasskeyRepository::class),
            new RelyingPartyIdRule(),
            new ServingHost(new RequestStack(), new FixedPublicBaseUrl($publicBaseUrl)),
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
