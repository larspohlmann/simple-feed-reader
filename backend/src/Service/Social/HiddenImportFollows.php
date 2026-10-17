<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Support\BlueskyActor;
use Psr\Log\LoggerInterface;

final readonly class HiddenImportFollows
{
    public function __construct(
        private HiddenProfilesInterface $hiddenProfiles,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return array<string, HiddenProfileModel> by the chosen identity; none when Bluesky cannot say, and the 403 of
     *                                          their RSS later moves them
     */
    public function among(SocialAccount $account, array $feeds): array
    {
        $actorByIdentity = SocialNetwork::Bluesky === $account->getNetwork() ? self::actorsOf($feeds) : [];
        if ([] === $actorByIdentity) {
            return [];
        }
        try {
            $hidden = $this->hiddenProfiles->hiddenAmong(array_values($actorByIdentity));
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning('Import could not tell which Bluesky follows are hidden; all come in by RSS.', [
                'exception' => $exception,
            ]);

            return [];
        }

        return self::byIdentity($actorByIdentity, $hidden);
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return array<string, string> each valid identity's actor as the hidden map keys it: a DID as is, a handle
     *                               lower-cased
     */
    private static function actorsOf(array $feeds): array
    {
        $actorByIdentity = [];
        foreach ($feeds as $feed) {
            if (BlueskyActor::isValid($feed->identity)) {
                $actorByIdentity[$feed->identity] = BlueskyActorKey::of($feed->identity);
            }
        }

        return $actorByIdentity;
    }

    /**
     * @param array<string, string>             $actorByIdentity
     * @param array<string, HiddenProfileModel> $hidden
     *
     * @return array<string, HiddenProfileModel>
     */
    private static function byIdentity(array $actorByIdentity, array $hidden): array
    {
        $hiddenByIdentity = [];
        foreach ($actorByIdentity as $identity => $actor) {
            if (isset($hidden[$actor])) {
                $hiddenByIdentity[$identity] = $hidden[$actor];
            }
        }

        return $hiddenByIdentity;
    }
}
