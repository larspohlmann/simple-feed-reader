<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\NotificationContextSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Support\Excerpt;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\LemmyAccountApi;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyNotificationContextSource implements NotificationContextSourceInterface
{
    public function __construct(private LemmyAccountApi $lemmy, private MarkdownHtml $markdown)
    {
    }

    public function withContext(SocialAccount $account, array $fresh): array
    {
        $rows = [];
        $throttled = false;
        foreach ($fresh as $row) {
            $answeredId = $row->references->answeredId;
            if ($throttled || $answeredId === null) {
                $rows[] = $row;
                continue;
            }
            try {
                $rows[] = $this->withAnswered($account, $row, $answeredId);
            } catch (SocialNetworkThrottledException) {
                $throttled = true;
                $rows[] = $row;
            } catch (SocialPostNotFoundException | SocialPostRefusedException | SocialNetworkUnreachableException) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function withAnswered(
        SocialAccount $account,
        FetchedNotificationModel $row,
        string $answeredId,
    ): FetchedNotificationModel {
        $content = JsonNodeModel::of($this->lemmy->get($account, '/api/v3/comment?id=' . $answeredId)->json())
            ->node('comment_view')
            ->node('comment')
            ->string('content');
        if ($content === null) {
            return $row;
        }
        $answered = Excerpt::of(PlainText::fromHtmlBlocks($this->markdown->of($content)));

        return $row->withContext($row->subject->context->withAnsweredExcerpt($answered));
    }
}
