<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\NotificationContext;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Service\Social\Social\MastodonAccountApi;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonNotificationContextSource implements NotificationContextSourceInterface
{
    private const int STATUSES_PER_CALL = 20;

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function contextsOf(SocialAccount $account, array $fresh): array
    {
        $statuses = [];
        foreach (array_chunk(self::answeredIds($fresh), self::STATUSES_PER_CALL) as $ids) {
            $statuses += $this->statusesOf($account, $ids);
        }

        $contexts = [];
        foreach ($fresh as $row) {
            $context = self::answered($row, $statuses);
            if ($context !== null) {
                $contexts[$row->remoteId] = $context;
            }
        }

        return $contexts;
    }

    /**
     * @param list<FetchedNotificationModel> $rows
     * @return list<string>
     */
    private static function answeredIds(array $rows): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (FetchedNotificationModel $row): ?string => $row->references->answeredId, $rows),
            static fn (?string $id): bool => $id !== null,
        )));
    }

    /**
     * @param list<string> $ids
     * @return array<string, JsonNodeModel> status by id
     */
    private function statusesOf(SocialAccount $account, array $ids): array
    {
        $query = implode('&', array_map(static fn (string $id): string => 'id[]=' . rawurlencode($id), $ids));
        try {
            $answer = $this->mastodon->askAboutPost($account, '/api/v1/statuses?' . $query);
        } catch (
            SocialPostNotFoundException | SocialPostRefusedException | SocialTokenScopeMissingException
            | SocialNetworkUnreachableException
        ) {
            return [];
        }

        $statuses = [];
        foreach (JsonNodeModel::listOf($answer->json()) ?? [] as $status) {
            $id = $status->string('id');
            if ($id !== null) {
                $statuses[$id] = $status;
            }
        }

        return $statuses;
    }

    /** @param array<string, JsonNodeModel> $statuses */
    private static function answered(FetchedNotificationModel $row, array $statuses): ?NotificationContext
    {
        $answeredId = $row->references->answeredId;
        if ($answeredId === null || !isset($statuses[$answeredId])) {
            return null;
        }
        $answered = $statuses[$answeredId];
        $context = self::withExcerptOf($row->subject->context, $answered);
        if ($answered->string('in_reply_to_id') !== null) {
            $context = $context->withOnComment();
        }

        return $context;
    }

    private static function withExcerptOf(NotificationContext $context, JsonNodeModel $answered): NotificationContext
    {
        $excerpt = Excerpt::ofHtml($answered->text('content'));
        if ($excerpt === null) {
            return $context;
        }

        return $context->withAnsweredExcerpt($excerpt);
    }
}
