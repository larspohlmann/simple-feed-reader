<?php

declare(strict_types=1);

namespace App\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Support\BlueskyAuthor;
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
        foreach (array_chunk(array_values(array_unique($actors)), AppViewClient::URIS_PER_CALL) as $batch) {
            foreach ($this->profilesOf($batch) as $key => $profile) {
                if (BlueskyProfileLabels::hidesFromLoggedOut($profile)) {
                    $hidden[$key] = self::modelOf($profile);
                }
            }
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
                0,
                $exception,
            );
        }
    }

    private static function modelOf(JsonNodeModel $profile): HiddenProfileModel
    {
        $did = (string) $profile->string('did');

        return new HiddenProfileModel($did, BlueskyAuthor::name($profile) ?? $did);
    }
}
