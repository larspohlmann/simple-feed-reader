<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationSource;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Notification\Support\MastodonIds;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\MastodonApiClient;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonNotificationSource implements NotificationSourceInterface
{
    private const string MARKERS = '/api/v1/markers';
    private const string NOTIFICATIONS = '/api/v1/notifications';
    private const string LIST = self::NOTIFICATIONS . '?limit=50&types[]=mention&types[]=favourite&types[]=reblog'
        . '&types[]=follow&types[]=poll&types[]=quote';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function fetch(SocialAccount $account): NotificationPageModel
    {
        $ownId = JsonNodeModel::of($this->ask($account, MastodonApiClient::VERIFY_CREDENTIALS)->json())->string('id');
        $markers = $this->ask($account, self::MARKERS . '?timeline[]=notifications');
        $lastReadId = JsonNodeModel::of($markers->json())->node('notifications')->string('last_read_id');
        $entries = JsonNodeModel::listOf($this->ask($account, self::LIST)->json()) ?? [];

        $notifications = [];
        foreach ($entries as $entry) {
            $notification = self::fetched($account, $entry, (string) $ownId, $lastReadId);
            if ($notification instanceof FetchedNotificationModel) {
                $notifications[] = $notification;
            }
        }

        return new NotificationPageModel($notifications, $this->seenUntil($account, $entries, $lastReadId));
    }

    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
    {
        $newest = MastodonIds::newest(array_map(
            static fn (SocialNotification $row): string => $row->getRemoteId(),
            $unseen,
        ));
        $request = ApiRequestModel::postJson(
            $account->getServiceUrl() . self::MARKERS,
            ['notifications' => ['last_read_id' => $newest]],
        );
        try {
            $this->mastodon->writeAs($account, $request, MastodonAccountApi::STATUSES_SCOPE);
        } catch (SocialPostNotFoundException | SocialPostRefusedException $exception) {
            throw new SocialNetworkUnreachableException($exception->getMessage(), 0, $exception);
        }

        foreach ($unseen as $row) {
            $marked($row);
        }
    }

    private function ask(SocialAccount $account, string $path): ApiResponseModel
    {
        try {
            return $this->mastodon->askAboutPost($account, $path);
        } catch (SocialPostNotFoundException | SocialPostRefusedException $exception) {
            throw new SocialNetworkUnreachableException($exception->getMessage(), 0, $exception);
        }
    }

    /** @param list<JsonNodeModel> $entries */
    private function seenUntil(SocialAccount $account, array $entries, ?string $lastReadId): ?\DateTimeImmutable
    {
        if ($lastReadId === null) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry->string('id') === $lastReadId) {
                return self::utc($entry->string('created_at'));
            }
        }

        try {
            $item = $this->mastodon->askAboutPost($account, self::NOTIFICATIONS . '/' . rawurlencode($lastReadId));
        } catch (SocialPostNotFoundException) {
            return null;
        } catch (SocialPostRefusedException $exception) {
            throw new SocialNetworkUnreachableException($exception->getMessage(), 0, $exception);
        }

        return self::utc(JsonNodeModel::of($item->json())->string('created_at'));
    }

    private static function fetched(
        SocialAccount $account,
        JsonNodeModel $entry,
        string $ownId,
        ?string $lastReadId,
    ): ?FetchedNotificationModel {
        $remoteId = $entry->string('id');
        $kind = self::kindOf($entry, $ownId);
        $createdAt = self::utc($entry->string('created_at'));
        $actor = self::actorOf($account, $entry->node('account'));
        if ($remoteId === null || $kind === null || $createdAt === null || $actor === null) {
            return null;
        }

        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            $actor,
            self::subjectOf($account, $kind, $entry, $remoteId, $actor->profileUrl),
            $createdAt,
            $lastReadId !== null && MastodonIds::atOrBefore($remoteId, $lastReadId),
        );
    }

    private static function actorOf(SocialAccount $account, JsonNodeModel $node): ?NotificationActor
    {
        $acct = $node->string('acct');
        if ($acct === null) {
            return null;
        }

        return new NotificationActor(
            $acct,
            $node->string('display_name'),
            $node->string('avatar'),
            self::profileUrl($account, $acct),
        );
    }

    private static function subjectOf(
        SocialAccount $account,
        SocialNotificationKind $kind,
        JsonNodeModel $entry,
        string $remoteId,
        string $actorProfileUrl,
    ): NotificationSubject {
        if ($kind === SocialNotificationKind::Follow) {
            return new NotificationSubject('', null, $actorProfileUrl);
        }

        $status = $entry->node('status');
        $authorAcct = $status->node('account')->string('acct');
        $linkUrl = $authorAcct === null || $status->string('id') === null
            ? $actorProfileUrl
            : self::profileUrl($account, $authorAcct) . '/' . $status->string('id');
        $standalone = $kind === SocialNotificationKind::Reply
            || $kind === SocialNotificationKind::Mention
            || $kind === SocialNotificationKind::Quote;

        return new NotificationSubject(
            $standalone ? $remoteId : $status->string('uri') ?? $remoteId,
            Excerpt::of(PlainText::fromHtmlBlocks($status->text('content'))),
            $linkUrl,
        );
    }

    private static function kindOf(JsonNodeModel $entry, string $ownId): ?SocialNotificationKind
    {
        return match ($entry->string('type')) {
            'favourite' => SocialNotificationKind::Like,
            'reblog' => SocialNotificationKind::Repost,
            'follow' => SocialNotificationKind::Follow,
            'poll' => SocialNotificationKind::Poll,
            'quote' => SocialNotificationKind::Quote,
            'mention' => $entry->node('status')->string('in_reply_to_account_id') === $ownId
                ? SocialNotificationKind::Reply
                : SocialNotificationKind::Mention,
            default => null,
        };
    }

    private static function profileUrl(SocialAccount $account, string $acct): string
    {
        return $account->getServiceUrl() . '/@' . $acct;
    }

    private static function utc(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}
