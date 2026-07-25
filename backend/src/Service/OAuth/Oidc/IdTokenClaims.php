<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc;

use App\Exception\OAuth\OAuthFailedException;

/**
 * The decoded payload of an ID token, and the one place that knows what shape a
 * claim arrived in.
 *
 * The split from {@see IdTokenVerifier} is between reading and deciding. This
 * class answers "what did the provider actually send"; the verifier answers "do
 * we accept it". So the accessors here return null for a claim of the wrong
 * type rather than throwing — a claim that is absent and a claim that is a
 * number where a string was due are both simply "no usable value", and it is
 * the verifier that turns that into the rejection message belonging to that
 * claim.
 *
 * Reading the shape here rather than at each call site is also what keeps the
 * verifier's guards down to one decision each: without it, every claim read
 * carries its own `is_string()` and the branching adds up fast.
 *
 * Decoding is deliberately NOT signature verification, and nothing in this file
 * should be read as validating the token. See {@see IdToken} for what stands
 * behind these bytes.
 */
final readonly class IdTokenClaims
{
    /**
     * @param array<string, mixed> $claims
     */
    private function __construct(private array $claims)
    {
    }

    /**
     * Splits the JWT and decodes its payload, refusing anything that is not a
     * JSON object.
     *
     * The header and signature segments are not read at all. Their presence is
     * checked only because a string with the wrong number of dots is not a JWT
     * and reading segment 1 of it would be reading something arbitrary.
     */
    public static function decode(IdToken $token): self
    {
        $segments = explode('.', $token->jwt);
        if (3 !== \count($segments)) {
            throw new OAuthFailedException('id_token is not a three-segment JWT');
        }

        $decoded = base64_decode(strtr($segments[1], '-_', '+/'), true);
        if (false === $decoded) {
            throw new OAuthFailedException('id_token payload is not valid base64url');
        }

        return new self(self::decodeObject($decoded));
    }

    /**
     * The claim as it arrived, for the two claims whose accepted shapes are
     * themselves a trust decision rather than a matter of type.
     *
     * `email_verified` is the reason this exists: Google sends a boolean and
     * Apple sends the string "true", and which spellings count as verified
     * belongs with the other trust decisions in the verifier, not here. Prefer
     * the typed readers below for everything else — they exist so a caller
     * cannot forget that a claim may be of any type at all.
     */
    public function claim(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * The claim if it is a string, null otherwise — including when it is absent.
     */
    public function string(string $name): ?string
    {
        $value = $this->claims[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * The claim if it is a JSON number with no fractional part, null otherwise.
     *
     * Deliberately not `is_numeric`: RFC 7519 defines `exp` as a JSON number,
     * so a token spelling it "1780000000" was not built by the provider.
     * Accepting the string would mean accepting a shape only a forger produces.
     */
    public function int(string $name): ?int
    {
        $value = $this->claims[$name] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * The claim as a list of strings, for claims RFC 7519 §4.1.3 allows to be
     * either one string or an array of them — `aud` being the one that matters.
     *
     * Members that are not strings are dropped rather than stringified, so
     * there is no shape of `aud` whose members can match a client id by
     * accident. Anything that is neither a string nor an array reads as an
     * empty list, which no comparison can match either.
     *
     * @return list<string>
     */
    public function stringList(string $name): array
    {
        $value = $this->claims[$name] ?? null;

        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $member) {
            if (\is_string($member)) {
                $strings[] = $member;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $json): array
    {
        try {
            $claims = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OAuthFailedException('id_token payload is not valid JSON', $e);
        }

        if (!\is_array($claims)) {
            throw new OAuthFailedException('id_token payload is not a JSON object');
        }

        // A JSON *array* also decodes to a PHP array, and would then sail into
        // the claim reads and fail there by luck rather than by decision. The
        // key check is what makes the return type below true rather than hoped.
        foreach (array_keys($claims) as $key) {
            if (!\is_string($key)) {
                throw new OAuthFailedException('id_token payload is a JSON array, not an object');
            }
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }
}
