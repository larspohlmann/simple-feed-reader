<?php

declare(strict_types=1);

namespace App\Service\Social\HomeServerCheck;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Model\BlueskyIdentityModel;
use App\Service\Social\Support\BlueskyRecordUri;
use Symfony\Component\HttpFoundation\Response;

/** Asks the author's own PDS, anonymously, for the post record: only its RecordNotFound proves the post deleted. */
final readonly class BlueskyHomeServerCheck implements HomeServerCheckInterface
{
    private const string GET_RECORD = '/xrpc/com.atproto.repo.getRecord?';
    private const string RECORD_NOT_FOUND = 'RecordNotFound';

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskyIdentityResolver $identities,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function confirmsDeleted(string $address): bool
    {
        $author = AtPostUri::authorityOf($address);
        if (null === $author) {
            return false;
        }
        try {
            $identity = $this->identities->resolve($author);
        } catch (SocialAccountUnresolvableException) {
            return false;
        }
        $recordKey = BlueskyRecordUri::recordKeyOf(AtPostUri::withAuthority($address, $identity->did));

        return null !== $recordKey && $this->pdsHasNoRecord($identity, $recordKey);
    }

    private function pdsHasNoRecord(BlueskyIdentityModel $identity, string $recordKey): bool
    {
        $query = http_build_query(
            ['repo' => $identity->did, 'collection' => BlueskyRecords::POST_COLLECTION, 'rkey' => $recordKey],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
        $answer = $this->bluesky->answer(ApiRequestModel::get($identity->pdsUrl . self::GET_RECORD . $query));

        return Response::HTTP_BAD_REQUEST === $answer->status
            && self::RECORD_NOT_FOUND === JsonNodeModel::of($answer->json())->string('error');
    }
}
