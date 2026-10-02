<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** MeJson::profile plus what /api/me needs a service for: whether mail is on, the timezone, the active AI connection. */
final readonly class MeProfileJson
{
    public function __construct(
        private MailCapability $mail,
        private ActiveAiJson $activeAi,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return [
            ...MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone),
            'ai' => $this->activeAi->of($user),
        ];
    }
}
