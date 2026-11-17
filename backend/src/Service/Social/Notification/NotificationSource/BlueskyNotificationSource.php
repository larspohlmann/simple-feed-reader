<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationSource;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Bluesky\Support\BlueskyWeb;
use App\Service\Social\Notification\BlueskyPostLookup;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\Support\BlueskyReplyReferences;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Notification\Support\NetworkTimestamp;
use App\Service\Social\Social\BlueskySessions;
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
        if (JsonNodeModel::of($unread->json())->int('count') === 0) {
            return new NotificationPageModel([], $this->clock->now());
        }

        $list = JsonNodeModel::of(BlueskySessions::accepted($this->sessions->askAs($account, self::LIST))->json());
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
            $kind = self::kindOf($entry);
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
            self::subjectOf($kind, $entry, $remoteId, $profileUrl, $account, $likedPosts),
            $createdAt,
            $entry->isTrue('isRead'),
            BlueskyReplyReferences::of($entry->node('record'), $kind),
        );
    }

    /** @param array<string, JsonNodeModel> $likedPosts */
    private static function subjectOf(
        SocialNotificationKind $kind,
        JsonNodeModel $entry,
        string $remoteId,
        string $actorProfileUrl,
        SocialAccount $account,
        array $likedPosts,
    ): NotificationSubject {
        $record = $entry->node('record');

        return match ($kind) {
            SocialNotificationKind::Like, SocialNotificationKind::Repost => self::likedSubject(
                self::likedPost($entry, $kind) ?? '',
                $account,
                $likedPosts,
            ),
            SocialNotificationKind::Follow => NotificationSubject::follow($actorProfileUrl),
            SocialNotificationKind::Reply => self::repliedSubject(
                self::postSubject($record, $remoteId, $actorProfileUrl),
                $account,
                $record->node('reply'),
            ),
            default => self::postSubject($record, $remoteId, $actorProfileUrl),
        };
    }

    private static function postSubject(
        JsonNodeModel $record,
        string $remoteId,
        string $actorProfileUrl,
    ): NotificationSubject {
        return new NotificationSubject(
            $remoteId,
            Excerpt::of($record->text('text')),
            AtPostUri::webUrl($remoteId) ?? $actorProfileUrl,
        );
    }

    private static function repliedSubject(
        NotificationSubject $subject,
        SocialAccount $account,
        JsonNodeModel $reply,
    ): NotificationSubject {
        return self::answersYourComment($account, $reply) ? self::aboutYourComment($subject) : $subject;
    }

    /** @param array<string, JsonNodeModel> $likedPosts */
    private static function likedSubject(
        string $postUri,
        SocialAccount $account,
        array $likedPosts,
    ): NotificationSubject {
        $record = ($likedPosts[$postUri] ?? JsonNodeModel::of(null))->node('record');
        $subject = new NotificationSubject(
            $postUri,
            Excerpt::of($record->text('text')),
            AtPostUri::webUrl($postUri) ?? BlueskyWeb::profileUrl($account->getIdentity()),
        );

        return $record->has('reply') && AtPostUri::authorityOf($postUri) === $account->getIdentity()
            ? self::aboutYourComment($subject)
            : $subject;
    }

    private static function answersYourComment(SocialAccount $account, JsonNodeModel $reply): bool
    {
        $parent = $reply->node('parent')->string('uri');

        return $parent !== null
            && AtPostUri::authorityOf($parent) === $account->getIdentity()
            && $reply->node('root')->string('uri') !== $parent;
    }

    private static function aboutYourComment(NotificationSubject $subject): NotificationSubject
    {
        return $subject->withContext($subject->context->withOnComment());
    }

    private static function likedPost(JsonNodeModel $entry, SocialNotificationKind $kind): ?string
    {
        return $kind === SocialNotificationKind::Like || $kind === SocialNotificationKind::Repost
            ? $entry->node('record')->node('subject')->string('uri')
            : null;
    }

    private static function kindOf(JsonNodeModel $entry): ?SocialNotificationKind
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
