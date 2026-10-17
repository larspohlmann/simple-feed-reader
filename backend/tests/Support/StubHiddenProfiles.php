<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\HiddenProfilesInterface;
use App\Service\Social\Model\HiddenProfileModel;

/** Knows no hidden profile until a test hides one, so no test asks the public AppView. */
final class StubHiddenProfiles implements HiddenProfilesInterface
{
    public ?SocialNetworkUnreachableException $failure = null;

    /** How many asks are answered before $failure is thrown. */
    public int $answeredBeforeFailure = 0;

    /** @var list<list<string>> */
    public array $asked = [];

    /** @var array<string, HiddenProfileModel> */
    private array $hidden = [];

    public function hide(HiddenProfileModel $profile, string ...$handles): void
    {
        $this->hidden[$profile->did] = $profile;
        foreach ($handles as $handle) {
            $this->hidden[BlueskyActorKey::of($handle)] = $profile;
        }
    }

    public function hiddenAmong(array $actors): array
    {
        $this->asked[] = $actors;
        if (null !== $this->failure && \count($this->asked) > $this->answeredBeforeFailure) {
            throw $this->failure;
        }

        return array_intersect_key($this->hidden, array_flip($actors));
    }
}
