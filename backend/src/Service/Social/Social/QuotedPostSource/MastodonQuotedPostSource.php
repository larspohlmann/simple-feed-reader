<?php

declare(strict_types=1);

namespace App\Service\Social\Social\QuotedPostSource;

use App\Entity\QuotedPostContent;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Content\LinkCard\Exception\QuotedPostUnavailableException;
use App\Service\Content\LinkCard\QuotedPostSource\QuotedPostSourceInterface;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\MastodonApiClient;
use App\Service\Social\Social\Support\MastodonQuotedStatus;
use App\Service\Social\Social\Support\MastodonStatusAddress;

/** Reads a quoted status from its own instance, anonymously, as #1615 reads its counts. */
final readonly class MastodonQuotedPostSource implements QuotedPostSourceInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public function quotedPostAt(string $address): QuotedPostContent
    {
        $statusUrl = MastodonStatusAddress::ofOrNull($address)?->apiUrl()
            ?? throw new QuotedPostUnavailableException('The address names no status on a Mastodon instance.');
        $status = JsonNodeModel::of($this->answerFor($statusUrl));
        if (!\in_array($address, [$status->string('uri'), $status->string('url')], true)) {
            throw new QuotedPostUnavailableException('The instance answered with another status.');
        }

        return MastodonQuotedStatus::of($status)
            ?? throw new QuotedPostUnavailableException('The status names no address or author to show.');
    }

    /** @return array<mixed> */
    private function answerFor(string $statusUrl): array
    {
        try {
            return MastodonApiClient::accepted($this->mastodon->answer(ApiRequestModel::get($statusUrl)))->json();
        } catch (SocialNetworkUnreachableException | SocialCredentialsRejectedException $exception) {
            throw new QuotedPostUnavailableException('The Mastodon instance gave no status.', previous: $exception);
        }
    }
}
