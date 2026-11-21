<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\NotificationContext;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Bluesky\BlueskyPostLookup;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyNotificationContextSource implements NotificationContextSourceInterface
{
    public function __construct(private BlueskyPostLookup $posts)
    {
    }

    public function findSubjects(SocialAccount $account, array $fresh): array
    {
        try {
            $posts = $this->posts->byUri($account, self::uris($account, $fresh));
        } catch (SocialNetworkUnreachableException) {
            return [];
        }

        $subjects = [];
        foreach ($fresh as $row) {
            $context = self::buildContext($account, $row, $posts);
            if ($context !== null) {
                $subjects[$row->remoteId] = $row->subject->withContext($context);
            }
        }

        return $subjects;
    }

    /**
     * @param list<FetchedNotificationModel> $rows
     *
     * @return list<string>
     */
    private static function uris(SocialAccount $account, array $rows): array
    {
        $uris = [];
        foreach ($rows as $row) {
            $uris[] = self::detectOwnAnswered($account, $row);
            $uris[] = self::detectForeignThreadRoot($account, $row);
        }

        return array_values(array_unique(array_filter($uris, static fn (?string $uri): bool => $uri !== null)));
    }

    private static function detectOwnAnswered(SocialAccount $account, FetchedNotificationModel $row): ?string
    {
        $answered = $row->references->answeredId;
        $isOwn = $answered !== null && AtPostUri::parseAuthority($answered) === $account->getIdentity();

        return $isOwn ? $answered : null;
    }

    private static function detectForeignThreadRoot(SocialAccount $account, FetchedNotificationModel $row): ?string
    {
        $root = $row->references->threadRootId;

        return $root !== null && AtPostUri::parseAuthority($root) !== $account->getIdentity() ? $root : null;
    }

    /** @param array<string, JsonNodeModel> $posts */
    private static function buildContext(
        SocialAccount $account,
        FetchedNotificationModel $row,
        array $posts,
    ): ?NotificationContext {
        $ownAnswered = self::findPost($posts, self::detectOwnAnswered($account, $row));
        $threadRoot = self::findPost($posts, self::detectForeignThreadRoot($account, $row));
        if ($ownAnswered === null && $threadRoot === null) {
            return null;
        }

        $context = $row->subject->context;
        if ($ownAnswered !== null) {
            $context = self::answering($context, $ownAnswered->node('record'));
        }
        if ($threadRoot !== null) {
            $author = $threadRoot->node('author');
            $context = $context->withThread(
                Excerpt::cutText($threadRoot->node('record')->text('text')),
                $author->string('displayName') ?? $author->string('handle'),
            );
        }

        return $context;
    }

    /** @param array<string, JsonNodeModel> $posts */
    private static function findPost(array $posts, ?string $uri): ?JsonNodeModel
    {
        return $uri === null ? null : $posts[$uri] ?? null;
    }

    private static function answering(NotificationContext $context, JsonNodeModel $answeredRecord): NotificationContext
    {
        $answering = $context->withAnsweredExcerpt(Excerpt::cutText($answeredRecord->text('text')));
        if (!$answeredRecord->has('reply')) {
            return $answering;
        }

        return $answering->withOnComment();
    }
}
