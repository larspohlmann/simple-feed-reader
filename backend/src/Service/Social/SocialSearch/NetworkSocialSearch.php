<?php

declare(strict_types=1);

namespace App\Service\Social\SocialSearch;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\BlueskySessions;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\TimelinePageModel;
use App\Service\Social\Support\BlueskySearchPage;
use App\Service\Social\Support\MastodonSearchPage;
use App\Service\Social\Support\SocialSearchPaths;

final readonly class NetworkSocialSearch implements SocialSearchInterface
{
    public function __construct(
        private BlueskySessions $blueskySessions,
        private MastodonAccountApi $mastodon,
        private MastodonApiClient $mastodonClient,
    ) {
    }

    public function newest(SocialAccount $account, string $query): TimelinePageModel
    {
        return match ($account->getNetwork()) {
            SocialNetwork::Bluesky => BlueskySearchPage::of(
                BlueskySessions::accepted($this->blueskySessions->askAs($account, SocialSearchPaths::bluesky($query))),
                $account->getIdentity(),
            ),
            SocialNetwork::Mastodon => MastodonSearchPage::of(MastodonApiClient::accepted(
                $this->mastodonClient->send(
                    $this->mastodon->authorizedGet($account, SocialSearchPaths::mastodon($query, null)),
                ),
            )),
        };
    }
}
