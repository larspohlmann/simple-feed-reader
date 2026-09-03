<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc;

use App\Exception\OAuth\OAuthFailedException;

/**
 * The decoded payload of an ID token, and the one place that knows what shape a
 * claim arrived in.
 *
 * The split from {@see IdTokenVerifier} is between reading and deciding: this
 * class answers "what did the provider send", the verifier answers "do we
 * accept it". Accessors return null for a claim of the wrong type rather than
 * throwing — absent and wrong-typed both mean "no usable value", and it is the
 * verifier that turns that into the rejection message for that claim. Reading
 * the shape here also keeps the verifier's guards to one decision each,
 * instead of an `is_string()` at every call site.
 *
 * Decoding is deliberately NOT signature verification — see {@see IdToken} for
 * what stands behind these bytes.
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
     * The header and signature segments are never read; their presence is
     * checked only so a string with the wrong number of dots isn't mistaken
     * for a JWT before segment 1 is decoded.
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
     * `email_verified` is why this exists: Google sends a boolean, Apple sends
     * the string "true", and which spellings count as verified is a trust
     * decision that belongs in the verifier, not here. Prefer the typed
     * readers below for everything else, so a caller cannot forget a claim
     * may be of any type.
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
     * so a token spelling it as the string "1780000000" was not built by the
     * provider — accepting it would accept a shape only a forger produces.
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
     * Non-string members are dropped rather than stringified, so no shape of
     * `aud` can match a client id by accident. Anything neither a string nor
     * an array reads as an empty list, which matches nothing either.
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
