<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\BlueskyPostLookup;
use App\Service\Social\Bluesky\BlueskySessions;
use App\Service\Social\Bluesky\Support\BlueskyReplyReferences;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\BlueskyPost\Support\BlueskyWeb;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Notification\Support\NetworkTimestamp;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyNotificationSource implements NotificationSourceInterface
{
    private const string UNREAD_COUNT = '/xrpc/app.bsky.notification.getUnreadCount';
    private const string LIST = '/xrpc/app.bsky.notification.listNotifications?limit=50';
    private const string UPDATE_SEEN = '/xrpc/app.bsky.notification.updateSeen';

    public function __construct(
        private BlueskySessions $sessions,
        private BlueskyPostLookup $posts,
        private NaiveUtcClock $clock,
    ) {
    }

    public function fetch(SocialAccount $account): NotificationPageModel
    {
        $unread = BlueskySessions::accepted($this->sessions->askAs($account, self::UNREAD_COUNT));
        if (JsonNodeModel::fromValue($unread->json())->int('count') === 0) {
            return new NotificationPageModel([], $this->clock->now());
        }

        $list = JsonNodeModel::fromValue(
            BlueskySessions::accepted($this->sessions->askAs($account, self::LIST))->json(),
        );
        $known = self::knownEntries($list->nodes('notifications'));
        $likedPosts = $this->posts->byUri($account, self::likedPosts($known));

        $notifications = [];
        foreach ($known as [$entry, $kind]) {
            $notification = self::fetched($account, $entry, $kind, $likedPosts);
            if ($notification instanceof FetchedNotificationModel) {
                $notifications[] = $notification;
            }
        }

        return new NotificationPageModel($notifications, NetworkTimestamp::toUtc($list->string('seenAt')));
    }

    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
    {
        $newest = max(array_map(
            static fn (SocialNotification $row): \DateTimeImmutable => $row->getCreatedAt(),
            $unseen,
        ));
        $marker = $newest->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.999\Z');
        SocialNetworkUnreachableException::around(
            fn () => BlueskySessions::accepted(
                $this->sessions->actAs($account, self::UPDATE_SEEN, ['seenAt' => $marker]),
            ),
        );

        foreach ($unseen as $row) {
            $marked($row);
        }
    }

    /**
     * @param list<JsonNodeModel> $entries
     *
     * @return list<array{JsonNodeModel, SocialNotificationKind}>
     */
    private static function knownEntries(array $entries): array
    {
        $known = [];
        foreach ($entries as $entry) {
            $kind = self::parseKind($entry);
            if ($kind instanceof SocialNotificationKind) {
                $known[] = [$entry, $kind];
            }
        }

        return $known;
    }

    /**
     * @param list<array{JsonNodeModel, SocialNotificationKind}> $known
     *
     * @return list<string>
     */
    private static function likedPosts(array $known): array
    {
        $posts = [];
        foreach ($known as [$entry, $kind]) {
            $post = self::likedPost($entry, $kind);
            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return array_values(array_unique($posts));
    }

    /** @param array<string, JsonNodeModel> $likedPosts */
    private static function fetched(
        SocialAccount $account,
        JsonNodeModel $entry,
        SocialNotificationKind $kind,
        array $likedPosts,
    ): ?FetchedNotificationModel {
        $remoteId = $entry->string('uri');
        $createdAt = NetworkTimestamp::toUtc($entry->string('indexedAt'));
        $author = $entry->node('author');
        $did = $author->string('did');
        $handle = $author->string('handle');
        if ($remoteId === null || $createdAt === null || $did === null || $handle === null) {
            return null;
        }
        $profileUrl = BlueskyWeb::profileUrl($did);

        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            new NotificationActor($handle, $author->string('displayName'), $author->string('avatar'), $profileUrl),
            self::buildSubject($kind, $entry, $remoteId, $profileUrl, $account, $likedPosts),
            $createdAt,
            $entry->isTrue('isRead'),
            BlueskyReplyReferences::fromRecord($entry->node('record'), $kind),
        );
    }

    /** @param array<string, JsonNodeModel> $likedPosts */
    private static function buildSubject(
        SocialNotificationKind $kind,
        JsonNodeModel $entry,
        string $remoteId,
        string $actorProfileUrl,
        SocialAccount $account,
        array $likedPosts,
    ): NotificationSubject {
        return match ($kind) {
            SocialNotificationKind::Like, SocialNotificationKind::Repost => self::likedSubject(
                self::likedPost($entry, $kind) ?? '',
                $account,
                $likedPosts,
            ),
            SocialNotificationKind::Follow => NotificationSubject::follow($actorProfileUrl),
            default => self::postSubject($entry, $remoteId, $actorProfileUrl),
        };
    }

    private static function postSubject(
        JsonNodeModel $entry,
        string $remoteId,
        string $actorProfileUrl,
    ): NotificationSubject {
        return new NotificationSubject(
            $remoteId,
            Excerpt::cutText($entry->node('record')->text('text')),
            AtPostUri::webUrl($remoteId) ?? $actorProfileUrl,
        );
    }

    /** @param array<string, JsonNodeModel> $likedPosts */
    private static function likedSubject(
        string $postUri,
        SocialAccount $account,
        array $likedPosts,
    ): NotificationSubject {
        $record = ($likedPosts[$postUri] ?? JsonNodeModel::fromValue(null))->node('record');
        $subject = new NotificationSubject(
            $postUri,
            Excerpt::cutText($record->text('text')),
            AtPostUri::webUrl($postUri) ?? BlueskyWeb::profileUrl($account->getIdentity()),
        );

        if (!$record->has('reply') || AtPostUri::parseAuthority($postUri) !== $account->getIdentity()) {
            return $subject;
        }

        return $subject->withContext($subject->context->withOnComment());
    }

    private static function likedPost(JsonNodeModel $entry, SocialNotificationKind $kind): ?string
    {
        return $kind === SocialNotificationKind::Like || $kind === SocialNotificationKind::Repost
            ? $entry->node('record')->node('subject')->string('uri')
            : null;
    }

    private static function parseKind(JsonNodeModel $entry): ?SocialNotificationKind
    {
        return match ($entry->string('reason')) {
            'like', 'like-via-repost' => SocialNotificationKind::Like,
            'repost', 'repost-via-repost' => SocialNotificationKind::Repost,
            'reply' => SocialNotificationKind::Reply,
            'mention' => SocialNotificationKind::Mention,
            'quote' => SocialNotificationKind::Quote,
            'follow' => SocialNotificationKind::Follow,
            default => null,
        };
    }
}
