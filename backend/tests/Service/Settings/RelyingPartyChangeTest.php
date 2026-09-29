<?php

declare(strict_types=1);

namespace App\Tests\Service\Settings;

use App\Exception\ValidationException;
use App\Http\RequestServingHost;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\EnrolledPasskeys\EnrolledPasskeysInterface;
use App\Service\Settings\Model\RelyingPartyIdChoiceModel;
use App\Service\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;
use App\Service\Settings\RelyingPartyChange;
use App\Service\Settings\RelyingPartyIdRule;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

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
            $this->choiceOf('green-tara.aardvark-koi.ts.net'),
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

        $change->guardAndInvalidatePasskeysIfChanged($this->choiceOf('com'));
    }

    /**
     * `settings.instance.passkeyHelp.rule3` (both locales) promises an IP
     * address is refused outright, with `localhost` the one exception.
     */
    public function testAnIpAddressRelyingPartyIdIsRefused(): void
    {
        $change = $this->change(currentRelyingPartyId: '203.0.113.5', publicBaseUrl: 'https://203.0.113.5');

        $this->expectException(ValidationException::class);

        $change->guardAndInvalidatePasskeysIfChanged($this->choiceOf('203.0.113.5'));
    }

    /** Development depends on this: rule3's one named exception must still work. */
    public function testLocalhostIsAccepted(): void
    {
        $change = $this->change(currentRelyingPartyId: 'localhost', publicBaseUrl: 'https://localhost');
        $this->expectNotToPerformAssertions();

        $change->guardAndInvalidatePasskeysIfChanged($this->choiceOf('localhost'));
    }

    public function testTheValidationErrorNamesTheFieldAndExplainsTheRule(): void
    {
        $change = $this->change(currentRelyingPartyId: 'example.test', publicBaseUrl: 'https://example.test');

        try {
            $change->guardAndInvalidatePasskeysIfChanged($this->choiceOf('com'));
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(
                ['passkeyRpId' => ['Must be a domain name, not an IP address or a bare top-level domain.']],
                $exception->errors,
            );
        }
    }

    private function choiceOf(string $passkeyRpId): RelyingPartyIdChoiceModel
    {
        return new RelyingPartyIdChoiceModel($passkeyRpId, invalidateExistingPasskeys: false);
    }

    private function change(string $currentRelyingPartyId, string $publicBaseUrl): RelyingPartyChange
    {
        return new RelyingPartyChange(
            $this->relyingPartyOf($currentRelyingPartyId),
            new EffectivePasskeyRelyingPartyId(),
            $this->createStub(EnrolledPasskeysInterface::class),
            new RelyingPartyIdRule(),
            new RequestServingHost(new RequestStack(), new FixedPublicBaseUrl($publicBaseUrl)),
        );
    }

    private function relyingPartyOf(string $id): PasskeyRelyingPartyInterface
    {
        return new class ($id) implements PasskeyRelyingPartyInterface {
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
