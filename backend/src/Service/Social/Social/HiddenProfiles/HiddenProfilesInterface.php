<?php

declare(strict_types=1);

namespace App\Service\Social\Social\HiddenProfiles;

use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\HiddenProfileModel;

interface HiddenProfilesInterface
{
    /**
     * @param list<string> $actors DIDs or handles
     *
     * @return array<string, HiddenProfileModel> the hidden ones among $actors, by DID and by lower-cased handle
     *
     * @throws SocialNetworkUnreachableException when Bluesky cannot say
     */
    public function hiddenAmong(array $actors): array;
}
