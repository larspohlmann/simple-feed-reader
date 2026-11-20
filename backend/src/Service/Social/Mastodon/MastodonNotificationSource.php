<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Mastodon\MastodonAccountApi;
use App\Service\Social\Mastodon\MastodonApiClient;
use App\Service\Social\Mastodon\Support\MastodonIds;
use App\Service\Social\Notification\Model\ContextReferenceModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Notification\Support\NetworkTimestamp;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
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
        $credentials = JsonNodeModel::fromValue($this->ask($account, MastodonApiClient::VERIFY_CREDENTIALS)->json());
        $ownId = $credentials->string('id')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance named no account id.');
        $markers = $this->ask($account, self::MARKERS . '?timeline[]=notifications');
        $lastReadId = JsonNodeModel::fromValue($markers->json())->node('notifications')->string('last_read_id');
        $entries = JsonNodeModel::parseNodes($this->ask($account, self::LIST)->json()) ?? [];

        $notifications = [];
        foreach ($entries as $entry) {
            $notification = self::fetched($account, $entry, $ownId, $lastReadId);
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
        SocialNetworkUnreachableException::around(
            fn () => $this->mastodon->writeAs($account, $request, MastodonAccountApi::STATUSES_SCOPE),
        );

        foreach ($unseen as $row) {
            $marked($row);
        }
    }

    private function ask(SocialAccount $account, string $path): ApiResponseModel
    {
        return SocialNetworkUnreachableException::around(fn () => $this->mastodon->askAboutPost($account, $path));
    }

    /** @param list<JsonNodeModel> $entries */
    private function seenUntil(SocialAccount $account, array $entries, ?string $lastReadId): ?\DateTimeImmutable
    {
        if ($lastReadId === null) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry->string('id') === $lastReadId) {
                return NetworkTimestamp::toUtc($entry->string('created_at'));
            }
        }

        try {
            $item = $this->mastodon->askAboutPost($account, self::NOTIFICATIONS . '/' . rawurlencode($lastReadId));
        } catch (SocialPostNotFoundException) {
            return null;
        } catch (SocialPostRefusedException $exception) {
            throw SocialNetworkUnreachableException::from($exception);
        }

        return NetworkTimestamp::toUtc(JsonNodeModel::fromValue($item->json())->string('created_at'));
    }

    private static function fetched(
        SocialAccount $account,
        JsonNodeModel $entry,
        string $ownId,
        ?string $lastReadId,
    ): ?FetchedNotificationModel {
        $remoteId = $entry->string('id');
        $kind = self::parseKind($entry, $ownId);
        $createdAt = NetworkTimestamp::toUtc($entry->string('created_at'));
        $actor = self::buildActor($account, $entry->node('account'));
        if ($remoteId === null || $kind === null || $createdAt === null || $actor === null) {
            return null;
        }

        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            $actor,
            self::buildSubject($account, $kind, $entry, $remoteId, $actor->profileUrl),
            $createdAt,
            $lastReadId !== null && MastodonIds::atOrBefore($remoteId, $lastReadId),
            self::buildReferences($kind, $entry->node('status')),
        );
    }

    private static function buildActor(SocialAccount $account, JsonNodeModel $node): ?NotificationActor
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

    private static function buildSubject(
        SocialAccount $account,
        SocialNotificationKind $kind,
        JsonNodeModel $entry,
        string $remoteId,
        string $actorProfileUrl,
    ): NotificationSubject {
        if ($kind === SocialNotificationKind::Follow) {
            return NotificationSubject::follow($actorProfileUrl);
        }

        $status = $entry->node('status');
        $authorAcct = $status->node('account')->string('acct');
        $linkUrl = $authorAcct === null || $status->string('id') === null
            ? $actorProfileUrl
            : self::profileUrl($account, $authorAcct) . '/' . $status->string('id');
        $groupKey = match ($kind) {
            SocialNotificationKind::Like, SocialNotificationKind::Repost, SocialNotificationKind::Poll
                => $status->string('uri') ?? $remoteId,
            default => $remoteId,
        };

        return new NotificationSubject(
            $groupKey,
            Excerpt::cutHtml($status->text('content')),
            $linkUrl,
            self::buildContext($kind, $status),
        );
    }

    private static function buildReferences(SocialNotificationKind $kind, JsonNodeModel $status): ContextReferenceModel
    {
        return new ContextReferenceModel(
            answeredId: $kind === SocialNotificationKind::Reply ? $status->string('in_reply_to_id') : null,
        );
    }

    private static function buildContext(SocialNotificationKind $kind, JsonNodeModel $status): NotificationContext
    {
        return match ($kind) {
            SocialNotificationKind::Like, SocialNotificationKind::Repost
                => self::aboutYourCommentWhenAReply(new NotificationContext(), $status),
            SocialNotificationKind::Quote => self::buildQuotedContext($status->node('quote')->node('quoted_status')),
            default => new NotificationContext(),
        };
    }

    private static function buildQuotedContext(JsonNodeModel $quoted): NotificationContext
    {
        return self::aboutYourCommentWhenAReply(
            new NotificationContext(answeredExcerpt: Excerpt::cutHtml($quoted->text('content'))),
            $quoted,
        );
    }

    private static function aboutYourCommentWhenAReply(
        NotificationContext $context,
        JsonNodeModel $status,
    ): NotificationContext {
        return $status->string('in_reply_to_id') === null ? $context : $context->withOnComment();
    }

    private static function parseKind(JsonNodeModel $entry, string $ownId): ?SocialNotificationKind
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
}
