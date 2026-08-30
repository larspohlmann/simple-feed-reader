<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Settings\PasskeyRelyingParty;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Builds the WebAuthn library's ceremony machinery. `final class`, not
 * `final readonly`: the managers are built lazily and memoised, which a
 * readonly property cannot do.
 */
final class PasskeyCeremony
{
    private ?CeremonyStepManager $creation = null;
    private ?CeremonyStepManager $request = null;
    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly PasskeyRelyingParty $relyingParty,
    ) {
    }

    public function creation(): CeremonyStepManager
    {
        return $this->creation ??= $this->factory()->creationCeremony();
    }

    public function request(): CeremonyStepManager
    {
        return $this->request ??= $this->factory()->requestCeremony();
    }

    public function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create(),
        ))->create();
    }

    /**
     * The wire shape of a set of ceremony options, as the client receives it.
     *
     * Lives here rather than in each options factory because both of them
     * already depend on this class for the serializer, and both need the
     * identical serialize-then-decode pair. Keeping one copy means a change
     * to the encoding — a JSON flag, say — cannot be applied to the
     * registration ceremony and forgotten for the login one.
     *
     * @return array<string, mixed>
     */
    public function encode(object $options): array
    {
        $json = $this->serializer()->serialize($options, 'json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * The registrable domain credentials are bound to. Delegates to
     * PasskeyRelyingParty rather than re-parsing PublicBaseUrl: the relying
     * party id already applies the "stored override, else derive from the
     * public base URL's host" rule (EffectivePasskeyRelyingPartyId), and a
     * second, independent derivation here could disagree with it whenever an
     * admin has configured an override.
     */
    public function host(): string
    {
        return $this->relyingParty->id();
    }

    /**
     * No allowed-origin list on purpose. Given none, the library compares the
     * browser's origin against the relying-party id itself (CheckOrigin) --
     * the spec rule, and the only one that works when a proxy rewrites Host so
     * the server cannot see the origin the browser is really at.
     *
     * `localhost` is exempted from the HTTPS requirement so development over
     * http keeps working, matching `settings.instance.passkeyHelp.rule4`.
     */
    private function factory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }
}
