<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The passkey enrolment response bodies (#624). `RegistrationOptionsFactory`
 * already returns the registration-options body in its final wire shape, so
 * `registrationOptions()` is a pass-through today. It stays its own mapper
 * rather than a private method on `PasskeyController` because
 * ThinControllerRule forbids response assembly there, and because the
 * credential-listing and revocation responses Task 8 adds belong beside this
 * one, not duplicated into a second convention.
 */
final readonly class PasskeyJson
{
    /**
     * @param array{options: array<string, mixed>, handle: string} $registrationOptions
     *
     * @return array{options: array<string, mixed>, handle: string}
     */
    public static function registrationOptions(array $registrationOptions): array
    {
        return $registrationOptions;
    }
}
