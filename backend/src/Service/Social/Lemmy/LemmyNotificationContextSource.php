<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnavailableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyNotificationContextSource implements NotificationContextSourceInterface
{
    public function __construct(private LemmyAccountApi $lemmy, private MarkdownHtml $markdown)
    {
    }

    public function findContexts(SocialAccount $account, array $fresh): array
    {
        $contexts = [];
        $excerpts = [];
        foreach ($fresh as $row) {
            $answeredId = $row->references->answeredId;
            if ($answeredId === null) {
                continue;
            }
            if (!array_key_exists($answeredId, $excerpts)) {
                try {
                    $excerpts[$answeredId] = $this->fetchExcerpt($account, $answeredId);
                } catch (SocialNetworkThrottledException | SocialNetworkUnavailableException) {
                    break;
                } catch (SocialPostNotFoundException | SocialPostRefusedException | SocialNetworkUnreachableException) {
                    $excerpts[$answeredId] = null;
                }
            }
            if ($excerpts[$answeredId] !== null) {
                $contexts[$row->remoteId] = $row->subject->context->withAnsweredExcerpt($excerpts[$answeredId]);
            }
        }

        return $contexts;
    }

    private function fetchExcerpt(SocialAccount $account, string $answeredId): ?string
    {
        $content = JsonNodeModel::fromValue($this->lemmy->get($account, '/api/v3/comment?id=' . $answeredId)->json())
            ->node('comment_view')
            ->node('comment')
            ->string('content');

        return Excerpt::cutHtml($content === null ? null : $this->markdown->toHtml($content));
    }
}
