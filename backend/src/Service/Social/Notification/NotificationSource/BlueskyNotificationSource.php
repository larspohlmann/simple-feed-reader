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
use App\Service\Social\Bluesky\AppViewClient;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Bluesky\Support\BlueskyWeb;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Notification\Support\NetworkTimestamp;
use App\Service\Social\Social\BlueskySessions;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyNotificationSource implements NotificationSourceInterface
{
    private const string UNREAD_COUNT = '/xrpc/app.bsky.notification.getUnreadCount';
    private const string LIST = '/xrpc/app.bsky.notification.listNotifications?limit=50';
    private const string UPDATE_SEEN = '/xrpc/app.bsky.notification.updateSeen';
    private const string GET_POSTS = '/xrpc/app.bsky.feed.getPosts?';

    public function __construct(
        private BlueskySessions $sessions,
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
        $entries = array_values(array_filter(
            $list->nodes('notifications'),
            static fn (JsonNodeModel $entry): bool => self::kindOf($entry) instanceof SocialNotificationKind,
        ));
        $postTexts = $this->postTexts($account, $entries);

        return new NotificationPageModel(
            array_values(array_filter(array_map(
                fn (JsonNodeModel $entry): ?FetchedNotificationModel => self::fetched($account, $entry, $postTexts),
                $entries,
            ))),
            NetworkTimestamp::toUtc($list->string('seenAt')),
        );
    }

    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
    {
        $newest = max(array_map(
            static fn (SocialNotification $row): \DateTimeImmutable => $row->getCreatedAt(),
            $unseen,
        ));
        $marker = $newest->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        try {
            BlueskySessions::accepted($this->sessions->actAs($account, self::UPDATE_SEEN, ['seenAt' => $marker]));
        } catch (SocialPostNotFoundException | SocialPostRefusedException $exception) {
            throw new SocialNetworkUnreachableException($exception->getMessage(), 0, $exception);
        }

        foreach ($unseen as $row) {
            $marked($row);
        }
    }

    /**
     * @param list<JsonNodeModel> $entries
     *
     * @return array<string, string> post text by AT URI, for the posts that likes and reposts point at
     */
    private function postTexts(SocialAccount $account, array $entries): array
    {
        $subjects = [];
        foreach ($entries as $entry) {
            $subject = self::likedPost($entry);
            if ($subject !== null) {
                $subjects[$subject] = $subject;
            }
        }

        $texts = [];
        foreach (array_chunk(array_values($subjects), AppViewClient::URIS_PER_CALL) as $uris) {
            $query = implode('&', array_map(static fn (string $uri): string => 'uris=' . rawurlencode($uri), $uris));
            $answer = BlueskySessions::accepted($this->sessions->askAs($account, self::GET_POSTS . $query));
            foreach (JsonNodeModel::of($answer->json())->nodes('posts') as $post) {
                $uri = $post->string('uri');
                if ($uri !== null) {
                    $texts[$uri] = $post->node('record')->text('text') ?? '';
                }
            }
        }

        return $texts;
    }

    /** @param array<string, string> $postTexts */
    private static function fetched(
        SocialAccount $account,
        JsonNodeModel $entry,
        array $postTexts,
    ): ?FetchedNotificationModel {
        $kind = self::kindOf($entry);
        $remoteId = $entry->string('uri');
        $createdAt = NetworkTimestamp::toUtc($entry->string('indexedAt'));
        $author = $entry->node('author');
        $did = $author->string('did');
        $handle = $author->string('handle');
        if ($kind === null || $remoteId === null || $createdAt === null || $did === null || $handle === null) {
            return null;
        }
        $profileUrl = BlueskyWeb::profileUrl($did);

        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            new NotificationActor($handle, $author->string('displayName'), $author->string('avatar'), $profileUrl),
            self::subjectOf($kind, $entry, $remoteId, $profileUrl, $account, $postTexts),
            $createdAt,
            $entry->isTrue('isRead'),
        );
    }

    /** @param array<string, string> $postTexts */
    private static function subjectOf(
        SocialNotificationKind $kind,
        JsonNodeModel $entry,
        string $remoteId,
        string $actorProfileUrl,
        SocialAccount $account,
        array $postTexts,
    ): NotificationSubject {
        return match ($kind) {
            SocialNotificationKind::Like, SocialNotificationKind::Repost => self::likedSubject(
                $entry->string('reasonSubject') ?? '',
                $account,
                $postTexts,
            ),
            SocialNotificationKind::Follow => new NotificationSubject('', null, $actorProfileUrl),
            default => new NotificationSubject(
                $remoteId,
                Excerpt::of($entry->node('record')->text('text')),
                AtPostUri::webUrl($remoteId) ?? $actorProfileUrl,
            ),
        };
    }

    /** @param array<string, string> $postTexts */
    private static function likedSubject(string $postUri, SocialAccount $account, array $postTexts): NotificationSubject
    {
        return new NotificationSubject(
            $postUri,
            Excerpt::of($postTexts[$postUri] ?? null),
            AtPostUri::webUrl($postUri) ?? BlueskyWeb::profileUrl($account->getIdentity()),
        );
    }

    private static function likedPost(JsonNodeModel $entry): ?string
    {
        $kind = self::kindOf($entry);

        return $kind === SocialNotificationKind::Like || $kind === SocialNotificationKind::Repost
            ? $entry->string('reasonSubject')
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
