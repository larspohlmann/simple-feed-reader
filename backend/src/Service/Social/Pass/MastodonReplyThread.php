<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Bluesky\Model\JsonNodeModel;

final readonly class MastodonReplyThread
{
    private const array SHOWN_VISIBILITIES = ['public', 'unlisted'];

    /** @var array<array-key, list<JsonNodeModel>> */
    private array $answersById;

    /**
     * @param string              $postId      the post's id on the instance that answered
     * @param list<JsonNodeModel> $descendants
     */
    public function __construct(private string $postId, private ?string $postAuthor, array $descendants)
    {
        $answersById = [];
        foreach (self::shownOnceEach($descendants) as $status) {
            $parent = $status->string('in_reply_to_id');
            if ($parent !== null && $status->string('id') !== $postId) {
                $answersById[$parent][] = $status;
            }
        }
        $this->answersById = $answersById;
    }

    /**
     * A status whose parent the instance left out, muted, blocked or deleted, is dropped with its answers.
     *
     * @return list<JsonNodeModel>
     */
    public function topLevel(): array
    {
        return $this->answersById[$this->postId] ?? [];
    }

    /** @return list<JsonNodeModel> */
    public function answersTo(JsonNodeModel $status): array
    {
        $id = $status->string('id');

        return $id === null ? [] : $this->answersById[$id] ?? [];
    }

    public function isByPostAuthor(JsonNodeModel $account): bool
    {
        return $this->postAuthor !== null
            && \in_array($this->postAuthor, [$account->string('url'), $account->string('uri')], true);
    }

    /**
     * A repeated id is read once: the first copy wins.
     *
     * @param list<JsonNodeModel> $descendants
     *
     * @return list<JsonNodeModel>
     */
    private static function shownOnceEach(array $descendants): array
    {
        $kept = [];
        foreach ($descendants as $status) {
            $id = $status->string('id');
            if ($id === null || isset($kept[$id]) || !self::isShown($status)) {
                continue;
            }
            $kept[$id] = $status;
        }

        return array_values($kept);
    }

    private static function isShown(JsonNodeModel $status): bool
    {
        return \in_array($status->string('visibility'), self::SHOWN_VISIBILITIES, true);
    }
}
