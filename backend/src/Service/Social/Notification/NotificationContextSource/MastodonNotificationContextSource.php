<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
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

    public function withContext(SocialAccount $account, array $fresh): array
    {
        $excerpts = [];
        foreach (array_chunk(self::answeredIds($fresh), self::STATUSES_PER_CALL) as $ids) {
            $excerpts += $this->excerptsOf($account, $ids);
        }

        return array_map(
            static fn (FetchedNotificationModel $row): FetchedNotificationModel => self::answered($row, $excerpts),
            $fresh,
        );
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
     * @return array<string, string|null>
     */
    private function excerptsOf(SocialAccount $account, array $ids): array
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

        $excerpts = [];
        foreach (JsonNodeModel::listOf($answer->json()) ?? [] as $status) {
            $id = $status->string('id');
            if ($id !== null) {
                $excerpts[$id] = Excerpt::ofHtml($status->text('content'));
            }
        }

        return $excerpts;
    }

    /** @param array<string, string|null> $excerpts */
    private static function answered(FetchedNotificationModel $row, array $excerpts): FetchedNotificationModel
    {
        $answeredId = $row->references->answeredId;
        $excerpt = $answeredId !== null && isset($excerpts[$answeredId]) ? $excerpts[$answeredId] : null;
        if ($excerpt === null) {
            return $row;
        }

        return $row->withContext($row->subject->context->withAnsweredExcerpt($excerpt));
    }
}
