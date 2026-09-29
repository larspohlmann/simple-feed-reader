<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\DependencyInjection\ProcessLifetimeState;
use App\Service\Settings\PasskeyRelyingParty\PasskeyRelyingPartyInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

#[ProcessLifetimeState('Library machinery built from no setting; host() reads the relying party each call')]
final class PasskeyCeremony
{
    private ?CeremonyStepManager $creation = null;
    private ?CeremonyStepManager $request = null;
    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly PasskeyRelyingPartyInterface $relyingParty,
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
     * The ceremony options as the client receives them, in one place so a change to the encoding reaches both
     * ceremonies.
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
     * The registrable domain credentials bind to, from PasskeyRelyingPartyInterface: deriving it again from the public
     * base URL would ignore an admin's override.
     */
    public function host(): string
    {
        return $this->relyingParty->id();
    }

    /**
     * No allowed-origin list: the library then checks the origin against the relying-party id, the spec rule and the
     * only one that works behind a proxy that rewrites Host. `localhost` may use http, for development.
     */
    private function factory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }
}
