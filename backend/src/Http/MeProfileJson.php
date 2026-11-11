<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Repository\CatalogFeedRepository;
use App\Service\Account\AccountSettings;
use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** MeJson::profile and MeJson::preferences plus the parts that need a service: mail, timezone, active AI, catalog availability. */
final readonly class MeProfileJson
{
    public function __construct(
        private MailCapability $mail,
        private AccountSettings $accountSettings,
        private ActiveAiJson $activeAi,
        private CatalogFeedRepository $catalogFeeds,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return [
            ...MeJson::profile($user, $this->mail->isEnabled()),
            'preferences' => MeJson::preferences(
                $this->accountSettings->of($user),
                null !== $user->getPasskeyOfferAnsweredAt(),
                $this->instanceTimezone,
            ),
            'ai' => $this->activeAi->of($user),
            'catalog' => ['hasEntries' => $this->catalogFeeds->hasPickableFeed()],
        ];
    }
}
