<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use App\Service\Auth\Support\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        // User::$email is VARCHAR(180): SQLite would store a longer one, MySQL strict mode 500s at flush.
        #[Assert\Length(max: 180)]
        // Refuses the `.invalid` TLD: OAuthUserFactory gives an identity without an address the predictable
        // placeholder `<provider>-<hash of sub>@oauth.invalid`, and registering it first would break that user's
        // first sign-in on uniq_user_email. Not on User::$email, which stores those placeholders.
        #[Assert\Regex(
            pattern: '/\.invalid$/i',
            message: 'That address is not a deliverable one.',
            match: false,
        )]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: PasswordPolicy::MINIMUM_LENGTH, max: PasswordPolicy::MAXIMUM_LENGTH)]
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
