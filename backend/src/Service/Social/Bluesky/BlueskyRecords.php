<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Bluesky\Support\BlueskyRecordUri;
use App\Service\Social\Bluesky\Support\BlueskyStrongRef;

/** Writes and deletes records in an account's own repo: likes and reposts of a post, and its own posts. */
final readonly class BlueskyRecords
{
    private const string CREATE_RECORD = '/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = '/xrpc/com.atproto.repo.deleteRecord';
    private const string GET_RECORD = '/xrpc/com.atproto.repo.getRecord';
    private const string RECORD_NOT_FOUND = 'RecordNotFound';

    public function __construct(
        private BlueskySessions $sessions,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return string|null the AT URI Bluesky gave the new record */
    public function createAbout(SocialPostModel $post, string $collection): ?string
    {
        $answer = $this->sessions->actAs($post->account, self::CREATE_RECORD, [
            'repo' => $post->account->getIdentity(),
            'collection' => $collection,
            'record' => [
                '$type' => $collection,
                'subject' => BlueskyStrongRef::of($post),
                'createdAt' => $this->now(),
            ],
        ]);

        return JsonNodeModel::of($answer->json())->string('uri');
    }

    /**
     * @param array<string, mixed> $fields    the post record beyond its type and creation time
     * @param string|null          $recordKey null lets Bluesky pick one
     *
     * @return string the AT URI Bluesky gave the new post
     *
     * @throws SocialNetworkUnreachableException when Bluesky answers without one
     */
    public function createPost(SocialAccount $account, array $fields, ?string $recordKey): string
    {
        $answer = $this->sessions->actAs($account, self::CREATE_RECORD, [
            'repo' => $account->getIdentity(),
            'collection' => BlueskyRecordUri::POST,
            ...(null === $recordKey ? [] : ['rkey' => $recordKey]),
            'record' => ['$type' => BlueskyRecordUri::POST, ...$fields, 'createdAt' => $this->now()],
        ]);

        return self::uriOf($answer->json(), 'Bluesky took the post but named no address for it.');
    }

    /**
     * @return string|null the AT URI of the account's own post under $recordKey, null when it has none
     *
     * @throws SocialNetworkUnreachableException
     */
    public function findPost(SocialAccount $account, string $recordKey): ?string
    {
        $answer = $this->sessions->askAs($account, self::GET_RECORD . '?' . http_build_query([
            'repo' => $account->getIdentity(),
            'collection' => BlueskyRecordUri::POST,
            'rkey' => $recordKey,
        ]));
        if (self::RECORD_NOT_FOUND === JsonNodeModel::of($answer->json())->string('error')) {
            return null;
        }

        return self::uriOf(BlueskySessions::accepted($answer)->json(), 'Bluesky found the post but named no address.');
    }

    /**
     * Bluesky answers a missing record without a commit, or with a 404.
     *
     * @throws SocialPostRefusedException when $recordUri names no record of $collection
     */
    public function delete(SocialAccount $account, string $collection, string $recordUri): PostDeletion
    {
        $recordKey = BlueskyRecordUri::recordKeyOf(BlueskyRecordUri::ofCollection($recordUri, $collection) ?? '')
            ?? throw new SocialPostRefusedException('The address names no Bluesky record of this kind.');
        try {
            $answer = $this->sessions->actAs($account, self::DELETE_RECORD, [
                'repo' => $account->getIdentity(),
                'collection' => $collection,
                'rkey' => $recordKey,
            ]);
        } catch (SocialPostNotFoundException) {
            return PostDeletion::AlreadyGone;
        }

        return JsonNodeModel::of($answer->json())->has('commit') ? PostDeletion::Deleted : PostDeletion::AlreadyGone;
    }

    /** @param array<mixed> $answer */
    private static function uriOf(array $answer, string $withoutOne): string
    {
        return JsonNodeModel::of($answer)->string('uri') ?? throw new SocialNetworkUnreachableException($withoutOne);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s.v\Z');
    }
}
