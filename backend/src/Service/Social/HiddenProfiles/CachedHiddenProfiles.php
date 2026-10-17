<?php

declare(strict_types=1);

namespace App\Service\Social\HiddenProfiles;

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
        foreach (array_unique($actors) as $actor) {
            $visibility = $this->profileVisibility->visibilityOf($actor);
            if (null !== $visibility->hidden) {
                $hidden[$actor] = $visibility->hidden;
            } elseif ($visibility->isUnknown()) {
                $unknown[] = $actor;
            }
        }

        return $hidden + $this->askedHidden($unknown);
    }

    /**
     * @param list<string> $unknown
     *
     * @return array<string, HiddenProfileModel>
     */
    private function askedHidden(array $unknown): array
    {
        if ([] === $unknown) {
            return [];
        }
        $hidden = $this->inner->hiddenAmong($unknown);
        $this->profileVisibility->remember($unknown, $hidden);

        return $hidden;
    }
}
