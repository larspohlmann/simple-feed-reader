<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        // App\Entity\User::$email is VARCHAR(180): without this SQLite truncates
        // silently while MySQL strict mode 500s at flush. Validation keeps the
        // two backends consistent.
        #[Assert\Length(max: 180)]
        // Blocks the address space this app mints for itself: OAuthAccountLinker
        // gives an identity with no usable address a DETERMINISTIC placeholder,
        // `<provider>-<sha256 prefix of sub>@oauth.invalid`. Because it is
        // predictable (and Assert\Email accepts it), an attacker holding a
        // victim's provider `sub` could register it here first and make the
        // victim's first sign-in die on uniq_user_email. `.invalid` is RFC 2606
        // reserved, so no address under it could receive the verification mail
        // anyway. It lives here, not on User::$email where the linker writes
        // those placeholders — a constraint there would refuse the very Apple
        // re-authorisers this protects.
        #[Assert\Regex(
            pattern: '/\.invalid$/i',
            message: 'That address is not a deliverable one.',
            match: false,
        )]
        public string $email = '',
        // 12 chars with no composition rules: length beats character classes,
        // and the passphrase people actually remember is the one they keep.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank(message: 'Complete the anti-spam challenge.')]
        public string $altcha = '',
        // The UI language, used only to localise this account's emails. Left
        // unvalidated on purpose: the service normalises it to a supported
        // locale (falling back to English), so a bad value never blocks a signup.
        public string $locale = 'en',
    ) {
    }
}
