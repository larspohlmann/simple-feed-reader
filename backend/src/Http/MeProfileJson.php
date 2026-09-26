<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** MeJson::profile plus the two instance facts every /api/me answer carries: whether mail is on, and the timezone. */
final readonly class MeProfileJson
{
    public function __construct(
        private MailCapability $mail,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone);
    }
}
