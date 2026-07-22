<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        // Not cosmetic: App\Entity\User::$email is VARCHAR(180). SQLite would
        // store an over-long address silently, MySQL strict mode would throw at
        // flush time as an unhandled 500. Validation is what keeps the two
        // backends behaving the same.
        #[Assert\Length(max: 180)]
        // Closes the door on the address space this application mints for
        // itself. OAuthAccountLinker gives an identity that arrives with no
        // usable address a DETERMINISTIC placeholder,
        // `<provider>-<sha256 prefix of sub>@oauth.invalid`, so the same
        // identity reconstructs the same address rather than accumulating one
        // account per sign-in. Deterministic also means predictable, and
        // Assert\Email is perfectly happy with such an address — so somebody
        // holding a victim's provider `sub` could register it here first and
        // make that victim's very first sign-in die on uniq_user_email.
        //
        // Not a general-purpose blocklist. `.invalid` is reserved by RFC 2606
        // so that it can never resolve; no address under it was ever going to
        // receive the verification mail this endpoint is about to send.
        //
        // It belongs HERE and not on User::$email. The entity is where the
        // linker writes those placeholders, and a constraint there would refuse
        // exactly the accounts this rule exists to protect — every Apple user
        // who re-authorises and arrives with a `sub` and nothing else.
        #[Assert\Regex(
            pattern: '/\.invalid$/i',
            match: false,
            message: 'That address is not a deliverable one.',
        )]
        public string $email = '',
        // 12 chars with no composition rules: length beats character classes,
        // and the passphrase people actually remember is the one they keep.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank(message: 'Complete the anti-spam challenge.')]
        public string $altcha = '',
    ) {
    }
}
