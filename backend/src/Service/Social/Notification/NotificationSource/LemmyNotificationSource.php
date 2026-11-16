<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationSource;

use App\Entity\NotificationActor;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Service\Content\Comments\Support\LemmyAuthor;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Notification\Support\NetworkTimestamp;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\LemmyAccountApi;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyNotificationSource implements NotificationSourceInterface
{
    private const string QUERY = '?sort=New&limit=50&unread_only=false';
    private const string REPLIES = '/api/v3/user/replies' . self::QUERY;
    private const string MENTIONS = '/api/v3/user/mention' . self::QUERY;
    private const string MARK_REPLY = '/api/v3/comment/mark_as_read';
    private const string MARK_MENTION = '/api/v3/user/mention/mark_as_read';

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function fetch(SocialAccount $account): NotificationPageModel
    {
        $replies = JsonNodeModel::of($this->ask($account, self::REPLIES)->json())->nodes('replies');
        $mentions = JsonNodeModel::of($this->ask($account, self::MENTIONS)->json())->nodes('mentions');
        $notifications = [];
        foreach ($replies as $entry) {
            $notifications[] = self::fetched($account, $entry, 'comment_reply', SocialNotificationKind::Reply);
        }
        foreach ($mentions as $entry) {
            $notifications[] = self::fetched($account, $entry, 'person_mention', SocialNotificationKind::Mention);
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
        $id = (int) substr(strstr($row->getRemoteId(), ':') ?: '', 1);
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
        try {
            return $this->lemmy->get($account, $path);
        } catch (SocialPostNotFoundException | SocialPostRefusedException $exception) {
            throw SocialNetworkUnreachableException::from($exception);
        }
    }

    private static function fetched(
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
        $remoteId = $kind->value . ':' . $itemId;

        return new FetchedNotificationModel(
            $remoteId,
            $kind,
            $actor,
            new NotificationSubject(
                $remoteId,
                Excerpt::of($entry->node('comment')->text('content')),
                $account->getServiceUrl() . '/comment/' . $commentId,
            ),
            $createdAt,
            $item->isTrue('read'),
        );
    }

    private static function actorOf(SocialAccount $account, JsonNodeModel $creator): ?NotificationActor
    {
        $name = $creator->string('name');
        $handle = LemmyAuthor::handle($name, $creator->string('actor_id'));
        if ($handle === null) {
            return null;
        }
        $handle = substr($handle, 1);

        return new NotificationActor(
            $handle,
            $creator->string('display_name'),
            $creator->string('avatar'),
            $account->getServiceUrl() . '/u/' . $handle,
        );
    }
}
