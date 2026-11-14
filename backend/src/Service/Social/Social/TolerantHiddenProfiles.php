<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Support\ProfileFeedUrl;
use Psr\Log\LoggerInterface;

/** For a caller that carries on as if no profile were hidden when Bluesky cannot say, and only logs it. */
final readonly class TolerantHiddenProfiles
{
    public function __construct(
        private HiddenProfilesInterface $hiddenProfiles,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $actors
     * @param string       $warning what the caller does without an answer, logged when Bluesky cannot say
     *
     * @return array<string, HiddenProfileModel> as HiddenProfilesInterface::hiddenAmong(); none when Bluesky cannot say
     */
    public function hiddenAmongOrNone(array $actors, string $warning): array
    {
        try {
            return $this->hiddenProfiles->hiddenAmong($actors);
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning($warning, ['exception' => $exception]);

            return [];
        }
    }

    /** The hidden profile a Bluesky profile URL names; null for any other URL, and when Bluesky cannot say. */
    public function hiddenAt(string $url, string $warning): ?HiddenProfileModel
    {
        $actor = ProfileFeedUrl::blueskyProfileActorOf($url);
        if (null === $actor) {
            return null;
        }

        return $this->hiddenAmongOrNone([$actor], $warning)[$actor] ?? null;
    }
}
