<?php

declare(strict_types=1);

namespace App\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\ProfileVisibilityCache;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator(decorates: AppViewHiddenProfiles::class)]
final readonly class CachedHiddenProfiles implements HiddenProfilesInterface
{
    public function __construct(
        private HiddenProfilesInterface $inner,
        private ProfileVisibilityCache $profileVisibility,
    ) {
    }

    public function hiddenAmong(array $actors): array
    {
        $hidden = [];
        $unknown = [];
        $visibilities = $this->profileVisibility->visibilitiesOf(array_values(array_unique($actors)));
        foreach ($visibilities as $actor => $visibility) {
            if (null !== $visibility->hidden) {
                $hidden[$actor] = $visibility->hidden;
            } elseif ($visibility->isUnknown()) {
                $unknown[] = $actor;
            }
        }

        return $hidden + $this->askUnknown($unknown);
    }

    /**
     * For a caller that has just read these actors' visibility as unknown. Each call's answer is remembered as it
     * arrives, so a later call that fails costs none of the earlier ones.
     *
     * @param list<string> $unknown
     *
     * @return array<string, HiddenProfileModel>
     *
     * @throws SocialNetworkUnreachableException when Bluesky cannot say
     */
    public function askUnknown(array $unknown): array
    {
        $hidden = [];
        foreach (array_chunk($unknown, AppViewClient::URIS_PER_CALL) as $batch) {
            $hiddenInBatch = $this->inner->hiddenAmong($batch);
            $this->profileVisibility->remember($batch, $hiddenInBatch);
            $hidden += $hiddenInBatch;
        }

        return $hidden;
    }
}
