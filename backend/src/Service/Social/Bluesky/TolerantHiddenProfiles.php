<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Service\Social\Bluesky\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Bluesky\Model\HiddenProfileModel;
use App\Service\Social\Bluesky\Support\BlueskyProfileFeed;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
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
     * @return array<string, HiddenProfileModel> as HiddenProfilesInterface::fetchHiddenProfiles(); none when
     *                                              Bluesky cannot say
     */
    public function fetchHiddenProfilesOrEmpty(array $actors, string $warning): array
    {
        try {
            return $this->hiddenProfiles->fetchHiddenProfiles($actors);
        } catch (SocialNetworkUnreachableException $exception) {
            $this->logger->warning($warning, ['exception' => $exception]);

            return [];
        }
    }

    /** The hidden profile a Bluesky profile URL names; null for any other URL, and when Bluesky cannot say. */
    public function findHiddenProfile(string $url, string $warning): ?HiddenProfileModel
    {
        $actor = BlueskyProfileFeed::parseProfileActor($url);
        if (null === $actor) {
            return null;
        }

        return $this->fetchHiddenProfilesOrEmpty([$actor], $warning)[$actor] ?? null;
    }
}
