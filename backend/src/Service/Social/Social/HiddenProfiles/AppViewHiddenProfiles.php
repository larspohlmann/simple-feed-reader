<?php

declare(strict_types=1);

namespace App\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Support\BlueskyProfileLabels;

final readonly class AppViewHiddenProfiles implements HiddenProfilesInterface
{
    public function __construct(private AppViewClient $appView)
    {
    }

    public function hiddenAmong(array $actors): array
    {
        if ($this->appView->isThrottled()) {
            throw new SocialNetworkUnreachableException('Bluesky asked us to wait before asking about profiles again.');
        }
        $hidden = [];
        foreach (array_chunk(array_unique($actors), AppViewClient::URIS_PER_CALL) as $batch) {
            $hidden += BlueskyProfileLabels::hiddenAmong($this->profilesOf($batch));
        }

        return $hidden;
    }

    /**
     * @param list<string> $batch
     *
     * @return array<string, JsonNodeModel>
     */
    private function profilesOf(array $batch): array
    {
        try {
            return $this->appView->profiles($batch);
        } catch (FetchException | AppViewAnswerException $exception) {
            throw new SocialNetworkUnreachableException(
                'Bluesky could not say whether a profile is hidden.',
                previous: $exception,
            );
        }
    }
}
