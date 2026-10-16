<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Bluesky\Model\JsonNodeModel;

final readonly class MastodonReplyThread
{
    private const array SHOWN_VISIBILITIES = ['public', 'unlisted'];

    /** @var list<JsonNodeModel> */
    private array $topLevel;

    /** @var array<array-key, list<JsonNodeModel>> */
    private array $answersById;

    /** @param list<JsonNodeModel> $descendants */
    public function __construct(private ?string $postAuthor, array $descendants)
    {
        $listed = self::idsOf($descendants);
        $topLevel = [];
        $answersById = [];
        foreach (self::shownOnceEach($descendants) as $status) {
            $parent = $status->string('in_reply_to_id');
            if ($parent !== null && isset($listed[$parent])) {
                $answersById[$parent][] = $status;
                continue;
            }
            $topLevel[] = $status;
        }
        $this->topLevel = $topLevel;
        $this->answersById = $answersById;
    }

    /**
     * The replies answering the post itself, by whatever id the post has on the answering instance.
     *
     * @return list<JsonNodeModel>
     */
    public function topLevel(): array
    {
        return $this->topLevel;
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
     * @param list<JsonNodeModel> $descendants
     *
     * @return array<array-key, true>
     */
    private static function idsOf(array $descendants): array
    {
        $ids = [];
        foreach ($descendants as $status) {
            $id = $status->string('id');
            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * A repeated id is read once: a second status under it would answer itself and never end.
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
