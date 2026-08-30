<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\PublicBaseUrl;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * Builds the WebAuthn library's ceremony machinery — the two
 * `CeremonyStepManager`s and the serializer they share a wire format with.
 *
 * `final class`, not `final readonly class` — the one deliberate exception to
 * this codebase's house style. `CeremonyStepManagerFactory::setAllowedOrigins()`
 * bakes its argument into the `CeremonyStepManager` it hands back, and the
 * allowed origin here is PublicBaseUrl, which reads the `instance_setting` row
 * from the database. That means the managers cannot be built at container
 * compile time — there would be no request, and so no guarantee the setting
 * has even been read yet — so this class defers building them to first use and
 * memoises the result for the rest of the request. A `readonly` property
 * cannot be assigned lazily outside the constructor, which is why the class
 * cannot be `readonly` while doing this.
 *
 * Two verification rules are already enforced by the managers this class
 * builds, and must NOT be re-implemented anywhere else:
 * - `CeremonyStepManagerFactory` wires `CheckCounter` to the library's
 *   `ThrowExceptionIfInvalid`, which rejects an authenticator whose signature
 *   counter has not advanced — the standard defence against a cloned
 *   authenticator. A later task adds LOGGING around that exception; nothing in
 *   this codebase should ever compare counters itself.
 * - `CheckUserVerification` enforces `userVerification: required` by reading
 *   it off the options passed into the ceremony, not from anything this class
 *   configures.
 */
final class PasskeyCeremony
{
    private ?CeremonyStepManager $creation = null;
    private ?CeremonyStepManager $request = null;
    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly PasskeyRelyingParty $relyingParty,
        private readonly PublicBaseUrl $publicBaseUrl,
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
     * Rebuilt on every call rather than memoised itself: creation() and
     * request() each memoise their own result, and the factory is cheap and
     * carries no state worth keeping beyond that.
     */
    private function factory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([$this->publicBaseUrl->get()]);

        return $factory;
    }
}
