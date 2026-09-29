<?php

declare(strict_types=1);

namespace App\Service\OAuth\Oidc\Model;

use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\Oidc\Pass\IdTokenVerifier;

/**
 * The decoded payload of an ID token: what the provider sent, never whether to accept it ({@see IdTokenVerifier}).
 * Accessors return null for an absent or wrong-typed claim. Decoding is not signature verification.
 */
final readonly class IdTokenClaimsModel
{
    /**
     * @param array<string, mixed> $claims
     */
    private function __construct(private array $claims)
    {
    }

    /** Reads the payload segment only; the other two segments are counted, never read. */
    public static function decode(IdTokenModel $token): self
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
     * The raw claim, for claims whose accepted shapes are a trust decision (`email_verified`: Google sends a boolean,
     * Apple the string "true"). Prefer the typed readers below for everything else.
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
     * The claim if it is a JSON integer, null otherwise. Not `is_numeric`: RFC 7519 makes `exp` a JSON number, so a
     * numeric string is a shape only a forger produces.
     */
    public function int(string $name): ?int
    {
        $value = $this->claims[$name] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * The claim as a list of strings, for claims RFC 7519 §4.1.3 allows as a string or an array (`aud`). Non-string
     * members are dropped, not cast, so no shape of `aud` can match a client id by accident.
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
        } catch (\JsonException $exception) {
            throw new OAuthFailedException('id_token payload is not valid JSON', $exception);
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
