<?php

declare(strict_types=1);

namespace App\Service\Social\QuotedPostSource;

use App\Entity\QuotedPostContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\LinkCard\Exception\QuotedPostUnavailableException;
use App\Service\LinkCard\QuotedPostSource\QuotedPostSourceInterface;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Support\MastodonQuotedStatus;
use App\Service\Social\Support\MastodonStatusAddress;

/** Reads a quoted status from its own instance, anonymously, as #1615 reads its counts. */
final readonly class MastodonQuotedPostSource implements QuotedPostSourceInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public function quotedPostAt(string $address): QuotedPostContent
    {
        $statusUrl = MastodonStatusAddress::statusApiUrlOrNull($address)
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
