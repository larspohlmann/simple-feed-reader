<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Service\Content\Comments\Support\LemmyAuthor;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\Support\LemmyCommentPath;
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

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyNotificationSource implements NotificationSourceInterface
{
    private const string QUERY = '?sort=New&limit=50&unread_only=false';
    private const string REPLIES = '/api/v3/user/replies' . self::QUERY;
    private const string MENTIONS = '/api/v3/user/mention' . self::QUERY;
    private const string MARK_REPLY = '/api/v3/comment/mark_as_read';
    private const string MARK_MENTION = '/api/v3/user/mention/mark_as_read';

    public function __construct(private LemmyAccountApi $lemmy, private MarkdownHtml $markdown)
    {
    }

    public function fetch(SocialAccount $account): NotificationPageModel
    {
        $replies = JsonNodeModel::fromValue($this->ask($account, self::REPLIES)->json())->nodes('replies');
        $mentions = JsonNodeModel::fromValue($this->ask($account, self::MENTIONS)->json())->nodes('mentions');
        $notifications = [];
        foreach ($replies as $entry) {
            $notifications[] = $this->fetched($account, $entry, 'comment_reply', SocialNotificationKind::Reply);
        }
        foreach ($mentions as $entry) {
            $notifications[] = $this->fetched($account, $entry, 'person_mention', SocialNotificationKind::Mention);
        }

        return new NotificationPageModel(array_values(array_filter($notifications)), null);
    }

    public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
    {
        foreach ($unseen as $row) {
            $this->markRead($account, $row);
            $marked($row);
        }
    }

    private function markRead(SocialAccount $account, SocialNotification $row): void
    {
        $id = self::itemIdOf($row->getRemoteId());
        try {
            if ($row->getKind() === SocialNotificationKind::Reply) {
                $this->lemmy->post($account, self::MARK_REPLY, ['comment_reply_id' => $id, 'read' => true]);

                return;
            }
            $this->lemmy->post($account, self::MARK_MENTION, ['person_mention_id' => $id, 'read' => true]);
        } catch (SocialPostNotFoundException) {
            return;
        } catch (SocialPostRefusedException $exception) {
            throw SocialNetworkUnreachableException::from($exception);
        }
    }

    private function ask(SocialAccount $account, string $path): ApiResponseModel
    {
        return SocialNetworkUnreachableException::around(fn () => $this->lemmy->get($account, $path));
    }

    private function fetched(
        SocialAccount $account,
        JsonNodeModel $entry,
        string $envelope,
        SocialNotificationKind $kind,
    ): ?FetchedNotificationModel {
        $item = $entry->node($envelope);
        $itemId = $item->int('id');
        $createdAt = NetworkTimestamp::toUtc($item->string('published'));
        $actor = self::actorOf($account, $entry->node('creator'));
        $commentId = $entry->node('comment')->int('id');
        if ($itemId === null || $createdAt === null || $actor === null || $commentId === null) {
            return null;
        }
        $remoteId = self::remoteIdOf($kind, $itemId);
        $answeredId = $kind === SocialNotificationKind::Reply
            ? LemmyCommentPath::parentOf($entry->node('comment')->string('path'))
            : null;
        $context = self::contextOf($entry);

        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            $actor,
            new NotificationSubject(
                $remoteId,
                $this->excerptOf($entry->node('comment')->string('content')),
                $account->getServiceUrl() . '/comment/' . $commentId,
                $answeredId === null ? $context : $context->withOnComment(),
            ),
            $createdAt,
            $item->isTrue('read'),
            new ContextReferenceModel(answeredId: $answeredId),
        );
    }

    private function excerptOf(?string $markdown): ?string
    {
        return $markdown === null ? null : Excerpt::ofHtml($this->markdown->toHtml($markdown));
    }

    private static function remoteIdOf(SocialNotificationKind $kind, int $itemId): string
    {
        return $kind->value . ':' . $itemId;
    }

    private static function contextOf(JsonNodeModel $entry): NotificationContext
    {
        $community = $entry->node('community')->string('name');

        return new NotificationContext(
            threadTitle: Excerpt::of($entry->node('post')->text('name')),
            threadPlace: $community === null ? null : 'c/' . $community,
        );
    }

    private static function itemIdOf(string $remoteId): int
    {
        return (int) substr(strstr($remoteId, ':') ?: '', 1);
    }

    private static function actorOf(SocialAccount $account, JsonNodeModel $creator): ?NotificationActor
    {
        $handle = LemmyAuthor::identity($creator->string('name'), $creator->string('actor_id'));
        if ($handle === null) {
            return null;
        }

        return new NotificationActor(
            $handle,
            $creator->string('display_name'),
            $creator->string('avatar'),
            $account->getServiceUrl() . '/u/' . $handle,
        );
    }
}
