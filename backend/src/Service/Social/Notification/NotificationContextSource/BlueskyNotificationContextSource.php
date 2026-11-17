<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\NotificationContext;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Notification\BlueskyPostLookup;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyNotificationContextSource implements NotificationContextSourceInterface
{
    public function __construct(private BlueskyPostLookup $posts)
    {
    }

    public function withContext(SocialAccount $account, array $fresh): array
    {
        try {
            $posts = $this->posts->byUri($account, self::uris($account, $fresh));
        } catch (SocialNetworkUnreachableException) {
            return $fresh;
        }

        return array_map(
            static fn (FetchedNotificationModel $row): FetchedNotificationModel => self::contextual(
                $account,
                $row,
                $posts,
            ),
            $fresh,
        );
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
            $uris[] = self::ownAnsweredOf($account, $row);
            $uris[] = self::threadRootOf($account, $row);
        }

        return array_values(array_unique(array_filter($uris, static fn (?string $uri): bool => $uri !== null)));
    }

    private static function ownAnsweredOf(SocialAccount $account, FetchedNotificationModel $row): ?string
    {
        $answered = $row->references->answeredId;

        return $answered !== null && AtPostUri::authorityOf($answered) === $account->getIdentity() ? $answered : null;
    }

    private static function threadRootOf(SocialAccount $account, FetchedNotificationModel $row): ?string
    {
        $root = $row->references->threadRootId;

        return $root !== null && AtPostUri::authorityOf($root) !== $account->getIdentity() ? $root : null;
    }

    /** @param array<string, JsonNodeModel> $posts */
    private static function contextual(
        SocialAccount $account,
        FetchedNotificationModel $row,
        array $posts,
    ): FetchedNotificationModel {
        $context = $row->subject->context;
        $ownAnswered = self::ownAnsweredOf($account, $row);
        if ($ownAnswered !== null && isset($posts[$ownAnswered])) {
            $context = self::answering($context, $posts[$ownAnswered]->node('record'));
        }
        $threadRoot = self::threadRootOf($account, $row);
        if ($threadRoot !== null && isset($posts[$threadRoot])) {
            $author = $posts[$threadRoot]->node('author');
            $context = $context->withThread(
                Excerpt::of($posts[$threadRoot]->node('record')->text('text')),
                $author->string('displayName') ?? $author->string('handle'),
            );
        }

        return $row->withContext($context);
    }

    private static function answering(NotificationContext $context, JsonNodeModel $answeredRecord): NotificationContext
    {
        $answering = $context->withAnsweredExcerpt(Excerpt::of($answeredRecord->text('text')));
        if (!$answeredRecord->has('reply')) {
            return $answering;
        }

        return $answering->withOnComment();
    }
}
